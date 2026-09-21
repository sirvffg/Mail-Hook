<?php
// =====================================================================
// Cloudflare Worker 邮件回调 — v4
// 纯接收 raw + 本地 MIME 解析 + 全量日志
// =====================================================================

define('IN_MAILHOOK', true);
require_once __DIR__ . '/db.php';

// ---------- 日志 ----------
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0755, true);
$LOG_FILE = $LOG_DIR . '/hook-' . date('Y-m-d') . '.log';

function hook_log(array $row): void {
    global $LOG_FILE;
    $row['time'] = date('Y-m-d H:i:s');
    $row['ip']   = $_SERVER['REMOTE_ADDR'] ?? '';
    @file_put_contents(
        $LOG_FILE,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ---------- 1. 方法 ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    hook_log(['stage' => 'method', 'ok' => false, 'err' => 'not POST']);
    exit;
}

// ---------- 2. 鉴权 ----------
$hookSecret = function_exists('get_hook_secret') ? get_hook_secret() : '';
$sentSecret = $_SERVER['HTTP_X_HOOK_TOKEN'] ?? '';
if ($hookSecret !== '' && !hash_equals($hookSecret, $sentSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad secret']);
    hook_log(['stage' => 'auth', 'ok' => false, 'err' => 'bad secret']);
    exit;
}

// ---------- 3. 读 JSON ----------
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    hook_log([
        'stage'    => 'json',
        'ok'       => false,
        'err'      => 'invalid json',
        'body_len' => strlen($rawBody),
        'body_head'=> substr($rawBody, 0, 200),
    ]);
    exit;
}

$recipient = trim($data['recipient'] ?? '');
if ($recipient === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing recipient']);
    hook_log(['stage' => 'recipient', 'ok' => false, 'err' => 'empty']);
    exit;
}

$sender  = (string)($data['sender']  ?? '');
$subject = (string)($data['subject'] ?? '');
$raw     = (string)($data['raw']     ?? '');

// ---------- 4. 记录接收 ----------
hook_log([
    'stage'      => 'received',
    'ok'         => true,
    'recipient'  => $recipient,
    'sender'     => $sender,
    'subject_in' => $subject,
    'raw_len'    => strlen($raw),
    'raw_head'   => substr($raw, 0, 300),
]);

// ---------- 5. 主题 MIME 解码 ----------
$subjectDecoded = $subject;
if ($subject !== '' && function_exists('iconv_mime_decode')) {
    $d = @iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($d !== false) $subjectDecoded = $d;
}
$subjectDecoded = trim(preg_replace('/\s+/u', ' ', $subjectDecoded));

// ---------- 6. 解析 MIME ----------
$body = '';
$html = '';
$parseErr = null;
if ($raw !== '') {
    try {
        [$body, $html] = parseMimeBody($raw);
    } catch (Throwable $e) {
        $parseErr = $e->getMessage();
    }
}

hook_log([
    'stage'      => 'parsed',
    'ok'         => $parseErr === null,
    'err'        => $parseErr,
    'subject'    => $subjectDecoded,
    'body_len'   => strlen($body),
    'body_head'  => mb_substr($body, 0, 100, 'UTF-8'),
    'html_len'   => strlen($html),
    'html_head'  => mb_substr($html, 0, 100, 'UTF-8'),
]);

// ---------- 7. 入库 ----------
$insertId = 0;
$dbErr = null;
try {
    $db = get_db();
    ensureRawColumn($db);

    $stmt = $db->prepare("INSERT INTO mails
        (recipient, subject, sender, body, html, received_at)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $recipient,
        $subjectDecoded !== '' ? $subjectDecoded : null,
        $sender         !== '' ? $sender         : null,
        $body           !== '' ? $body           : null,
        $html           !== '' ? $html           : null,
        time(),
    ]);

    $insertId = (int)$db->lastInsertId();

    if ($raw !== '') {
        $db->prepare("UPDATE mails SET raw = ? WHERE id = ?")
           ->execute([$raw, $insertId]);
    }
} catch (Throwable $e) {
    $dbErr = $e->getMessage();
    error_log('[hook] db fail: ' . $dbErr);
}

hook_log([
    'stage'     => 'db',
    'ok'        => $dbErr === null,
    'err'       => $dbErr,
    'insert_id' => $insertId,
    'raw_len'   => strlen($raw),
]);

// ---------- 8. 返回 ----------
echo json_encode([
    'ok'       => $dbErr === null,
    'id'       => $insertId,
    'mail'     => $recipient,
    'subject'  => $subjectDecoded,
    'body_len' => strlen($body),
    'html_len' => strlen($html),
    'raw_len'  => strlen($raw),
    'error'    => $dbErr,
]);

// =====================================================================
// 确保 raw 字段存在
// =====================================================================
function ensureRawColumn(PDO $db): void {
    try {
        $cols = $db->query("SHOW COLUMNS FROM mails LIKE 'raw'");
        if (!$cols->fetch()) {
            $db->exec("ALTER TABLE mails ADD COLUMN raw MEDIUMTEXT NULL AFTER html");
        }
    } catch (Throwable $e) {
        error_log('[hook] ensure raw column fail: ' . $e->getMessage());
    }
}

// =====================================================================
// MIME 解析 — 针对真实 raw 验证过
// =====================================================================
function parseMimeBody(string $raw): array {
    $plain = '';
    $html  = '';

    // 找 boundary
    $boundary = null;
    if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $raw, $bm)) {
        $boundary = $bm[1];
    } elseif (preg_match('/boundary\s*=\s*([^\s;]+)/i', $raw, $bm)) {
        $boundary = trim($bm[1], '"');
    }

    if ($boundary !== null) {
        $delimiter = '--' . $boundary;
        $parts = explode($delimiter, $raw);

        foreach ($parts as $part) {
            // 只去掉前导换行，正文里的空白不能 trim
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) continue;

            [$headers, $content] = splitHeaderBody($part);
            if ($headers === null) continue;

            if (preg_match('/Content-Type:\s*text\/plain/i', $headers)) {
                $plain = decodeContent($content, $headers);
            } elseif (preg_match('/Content-Type:\s*text\/html/i', $headers)) {
                $html = decodeContent($content, $headers);
            }
        }
    } else {
        [$headers, $content] = splitHeaderBody($raw);
        if ($headers !== null) {
            if (preg_match('/Content-Type:\s*text\/html/i', $headers)) {
                $html = decodeContent($content, $headers);
            } else {
                $plain = decodeContent($content, $headers);
            }
        }
    }

    // 只有 html 时生成纯文本兜底
    if ($plain === '' && $html !== '') {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return [$plain, $html];
}

function splitHeaderBody(string $part): array {
    $sepPos = strpos($part, "\r\n\r\n");
    $sepLen = 4;
    if ($sepPos === false) {
        $sepPos = strpos($part, "\n\n");
        $sepLen = 2;
    }
    if ($sepPos === false) return [null, ''];

    $headers = substr($part, 0, $sepPos);
    $content = substr($part, $sepPos + $sepLen);
    $content = rtrim($content, "\r\n");
    return [$headers, $content];
}

function decodeContent(string $content, string $headers): string {
    // 1. 传输编码
    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headers)) {
        $clean = preg_replace('/\s+/', '', $content);
        $decoded = base64_decode($clean, true);
        if ($decoded !== false) $content = $decoded;
    } elseif (preg_match('/Content-Transfer-Encoding:\s*(?:quoted-printable|qp)/i', $headers)) {
        $content = quoted_printable_decode($content);
    }

    // 2. charset → UTF-8
    if (preg_match('/charset\s*=\s*"?([\w-]+)"?/i', $headers, $cm)) {
        $charset = strtoupper($cm[1]);
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $content);
            if ($converted !== false) $content = $converted;
        }
    }

    return trim($content);
}
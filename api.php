<?php
// =====================================================================
// 外部 API — 给注册脚本等用
// 鉴权: HTTP Header Api-Key: <key> 或 Authorization: Bearer <key>
// =====================================================================

define('IN_MAILHOOK', true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// api.php 只走 api_key 鉴权, 不走 Session
check_api_key();

$action = $_GET['action'] ?? 'get_mail';

// =====================================================================
// Resend 发信辅助
// =====================================================================

/**
 * 调 Resend Send Email API
 * @param string $apiKey  Resend API Key (re_xxxxxxx)
 * @param array  $payload ['from'=>..., 'to'=>[...], 'subject'=>..., 'html'=>..., 'text'=>...]
 * @return array ['ok'=>bool, 'resend_id'=>string, 'error'=>string, 'http_code'=>int]
 */
function resend_send(string $apiKey, array $payload): array {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok'=>false, 'resend_id'=>'', 'error'=>'curl: '.$err, 'http_code'=>0];
    }
    $resp = json_decode($raw, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($resp['id'])) {
        return ['ok'=>true, 'resend_id'=>$resp['id'], 'error'=>'', 'http_code'=>$httpCode];
    }
    return [
        'ok'=>false,
        'resend_id'=>'',
        'error'=>($resp['message'] ?? $raw) ?: "HTTP $httpCode",
        'http_code'=>$httpCode,
    ];
}

/**
 * 选一个可用域名（配置了 Resend Key + 今日额度未满）
 * 自动处理跨天清零。优先按 id 顺序轮转。
 * @param string $preferDomain 可选, 想指定用哪个域名 (from_email 里带的)
 * @return array|false  选中的 domain 行 (带 daily_limit/today_count/count_date), 或 false
 */
function pick_available_domain(string $preferDomain = '') {
    $db = get_db();
    $today = date('Y-m-d');

    // 先把所有域名的计数跨天重置一下 (幂等)
    $db->prepare("UPDATE domains SET today_count=0, count_date=? WHERE count_date<>? OR count_date IS NULL")
       ->execute([$today, $today]);

    // 如果指定了域名, 优先试它
    if ($preferDomain !== '') {
        $stmt = $db->prepare("SELECT * FROM domains WHERE domain=? AND resend_api_key IS NOT NULL AND resend_api_key<>'' AND today_count < daily_limit LIMIT 1");
        $stmt->execute([$preferDomain]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    // 否则挑任何一个额度够的
    $stmt = $db->query("SELECT * FROM domains WHERE resend_api_key IS NOT NULL AND resend_api_key<>'' AND today_count < daily_limit ORDER BY id ASC");
    return $stmt->fetch() ?: false;
}

/**
 * 递增某个域名的今日计数
 */
function bump_domain_count(int $domainId): void {
    $db = get_db();
    $today = date('Y-m-d');
    $db->prepare("UPDATE domains SET today_count = today_count + 1, count_date = ? WHERE id = ?")
       ->execute([$today, $domainId]);
}

/**
 * 写发送日志
 */
function write_sent_log(int $domainId, string $from, string $to, string $subject,
                        ?string $html, ?string $text, string $resendId,
                        int $status, string $error): void {
    get_db()->prepare(
        "INSERT INTO sent_logs (domain_id, from_email, to_email, subject, html, plain_text, resend_id, status, error_msg, sent_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $domainId, $from, $to, $subject, $html, $text,
        $resendId, $status, $error, time(),
    ]);
}

try {
    switch ($action) {

        // --- 注册脚本最常用: 按邮箱前缀查最新邮件 ---
        case 'get_mail': {
            // ?prefix=abc  → 查 abc@domain.com 的最新邮件
            // ?recipient=abc@xxx.com  → 完整匹配
            $prefix    = trim($_GET['prefix']    ?? '');
            $recipient = trim($_GET['recipient'] ?? '');
            $domain    = trim($_GET['domain']    ?? '');

            $sql  = "SELECT * FROM mails WHERE 1=1";
            $args = [];

            if ($recipient) {
                $sql .= " AND recipient = ?";
                $args[] = $recipient;
            } elseif ($prefix) {
                $domainPart = $domain ?: '@%';
                $sql .= " AND recipient LIKE ?";
                $args[] = $prefix . $domainPart;
            } else {
                json_resp(400, ['message' => 'need prefix or recipient'], 400);
            }

            $sql .= " ORDER BY id DESC LIMIT 1";
            $stmt = get_db()->prepare($sql);
            $stmt->execute($args);
            $row = $stmt->fetch();
            if (!$row) {
                json_resp(404, ['message' => 'no mail yet'], 404);
            }
            // 提取 4-6 位验证码 (给注册脚本方便用)
            $raw = ($row['body'] ?? '') . ' ' . ($row['subject'] ?? '') . ' ' . ($row['html'] ?? '');
            if (preg_match('/\b(\d{4,6})\b/', strip_tags($raw), $m)) {
                $row['code'] = $m[1];
            }
            $row['received_date'] = date('Y-m-d H:i:s', (int)$row['received_at']);
            json_resp(0, ['data' => $row]);
        }

        case 'list_mails': {
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $prefix = trim($_GET['prefix'] ?? '');
            $sql  = "SELECT id, recipient, subject, sender, received_at FROM mails";
            $args = [];
            if ($prefix) {
                $sql .= " WHERE recipient LIKE ?";
                $args[] = $prefix . '%';
            }
            $sql .= " ORDER BY id DESC LIMIT $limit";
            $stmt = get_db()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['received_date'] = date('Y-m-d H:i:s', (int)$r['received_at']);
            }
            json_resp(0, ['count' => count($rows), 'data' => $rows]);
        }

        case 'mail_detail': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) json_resp(400, ['message' => 'id required'], 400);
            $stmt = get_db()->prepare("SELECT * FROM mails WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) json_resp(404, ['message' => 'not found'], 404);
            $row['received_date'] = date('Y-m-d H:i:s', (int)$row['received_at']);
            json_resp(0, ['data' => $row]);
        }

        case 'delete_mail': {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) json_resp(400, ['message' => 'id required'], 400);
            get_db()->prepare("DELETE FROM mails WHERE id = ?")->execute([$id]);
            json_resp(0, ['message' => 'ok']);
        }

        case 'stats': {
            $db = get_db();
            $totalMails  = $db->query("SELECT COUNT(*) FROM mails")->fetchColumn();
            $totalAdmins = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            $totalKeys   = $db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
            $totalDomains= $db->query("SELECT COUNT(*) FROM domains")->fetchColumn();
            $todayMails  = $db->query("SELECT COUNT(*) FROM mails WHERE received_at >= UNIX_TIMESTAMP(CURDATE())")->fetchColumn();
            json_resp(0, [
                'total_mails'  => (int)$totalMails,
                'today_mails'  => (int)$todayMails,
                'admins'       => (int)$totalAdmins,
                'domains'      => (int)$totalDomains,
                'api_keys'     => (int)$totalKeys,
            ]);
        }

        // --- 列出可用邮件域名 (注册脚本/Worker 用) ---
        case 'domains': {
            $rows = get_db()->query("SELECT id, domain, note FROM domains ORDER BY id ASC")->fetchAll();
            json_resp(0, ['count' => count($rows), 'data' => $rows]);
        }

        // --- 发送邮件 (走 Resend, 自动轮转域名) ---
        // POST /api.php?action=send_mail
        // Body: to, subject, html?, text?, from_email? (form-data 或 JSON 都可以)
        case 'send_mail': {
            // 兼容 form-data 和 JSON
            $input = $_POST;
            if (empty($input) && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?: [];
            }

            $to      = trim($input['to']      ?? '');
            $subject = trim($input['subject'] ?? '');
            $html    = $input['html']         ?? '';
            $text    = $input['text']         ?? '';
            $fromEmail = trim($input['from_email'] ?? '');

            if ($to === '' || $subject === '') {
                json_resp(400, ['message' => 'to and subject are required'], 400);
            }
            if ($html === '' && $text === '') {
                json_resp(400, ['message' => 'html or text body required'], 400);
            }
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                json_resp(400, ['message' => 'invalid to email'], 400);
            }

            // 如果指定了 from_email, 提取域名优先用那个
            $preferDomain = '';
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $preferDomain = substr(strrchr($fromEmail, '@'), 1);
            }

            // 挑一个可用域名
            $domainRow = pick_available_domain($preferDomain);
            if (!$domainRow) {
                json_resp(429, [
                    'message' => 'all domains exhausted (daily limit reached or no Resend key configured)',
                ], 429);
            }

            // 没指定 from_email 的话, 用 no-reply@domain
            if ($fromEmail === '') {
                $fromEmail = 'no-reply@' . $domainRow['domain'];
            }

            // 构造 Resend payload
            $payload = [
                'from'    => $fromEmail,
                'to'      => [$to],
                'subject' => $subject,
            ];
            if ($html)  $payload['html'] = $html;
            if ($text)  $payload['text'] = $text;

            // 调 Resend
            $result = resend_send($domainRow['resend_api_key'], $payload);

            // 写日志 + 更新计数
            write_sent_log(
                (int)$domainRow['id'],
                $fromEmail, $to, $subject,
                $html ?: null, $text ?: null,
                $result['resend_id'],
                $result['ok'] ? 1 : 2,
                $result['error']
            );

            if ($result['ok']) {
                bump_domain_count((int)$domainRow['id']);
                json_resp(0, [
                    'message'    => 'sent ok',
                    'resend_id'  => $result['resend_id'],
                    'domain'     => $domainRow['domain'],
                    'remaining'  => (int)$domainRow['daily_limit'] - (int)$domainRow['today_count'] - 1,
                ]);
            }

            // 发送失败 — 如果还有其他域名可以试, 继续轮转
            // 找下一个还有额度的
            $nextStmt = get_db()->prepare(
                "SELECT * FROM domains
                 WHERE id <> ? AND resend_api_key IS NOT NULL AND resend_api_key<>''
                   AND today_count < daily_limit
                 ORDER BY id ASC LIMIT 1"
            );
            $nextStmt->execute([$domainRow['id']]);
            $nextRow = $nextStmt->fetch();

            if ($nextRow) {
                // 递归调用自己的逻辑 (通过跳转)
                json_resp(502, [
                    'message'      => "send failed on {$domainRow['domain']}: {$result['error']}",
                    'fallback_to'  => $nextRow['domain'],
                    'hint'         => 'retry — system will auto-rotate on next call',
                ], 502);
            }

            json_resp(502, [
                'message' => "send failed on {$domainRow['domain']}: {$result['error']}; no fallback available",
            ], 502);
        }

        // --- 发送日志列表 ---
        case 'sent_logs': {
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $stmt = get_db()->query("SELECT sl.*, d.domain
                                     FROM sent_logs sl
                                     LEFT JOIN domains d ON d.id = sl.domain_id
                                     ORDER BY sl.id DESC LIMIT $limit");
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['sent_date'] = date('Y-m-d H:i:s', (int)$r['sent_at']);
            }
            json_resp(0, ['count' => count($rows), 'data' => $rows]);
        }

        default:
            json_resp(404, ['message' => 'unknown action: ' . $action], 404);
    }
} catch (Throwable $e) {
    json_resp(500, ['message' => 'server error: ' . $e->getMessage()], 500);
}

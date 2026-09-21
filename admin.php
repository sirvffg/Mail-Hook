<?php
// =====================================================================
// 邮件路由管理后台 — Session 登录
// =====================================================================

define('IN_MAILHOOK', true);
require_once __DIR__ . '/db.php';

start_session();
$db = get_db();

// ---------- 首次安装 ----------
$adminCount = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$isFirstInstall = ($adminCount == 0);

// ---------- 处理 POST 动作 ----------
$flashMsg = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    try {
        switch ($do) {

            // -- 首次安装: 创建第一个 admin --
            case 'install': {
                if (!$isFirstInstall) { header('Location: admin.php'); exit; }
                $u = trim($_POST['username'] ?? '');
                $p = $_POST['password'] ?? '';
                if (strlen($u) < 2 || strlen($p) < 6) throw new Exception('用户名≥2位, 密码≥6位');
                $hash = password_hash($p, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO admins (username, password_hash, created_at, updated_at) VALUES (?,?,?,?)")
                   ->execute([$u, $hash, time(), time()]);
                // 同时给 settings 写入初始 hook_secret (已存在则更新)
                $db->prepare("INSERT INTO settings (id, hook_secret, updated_at) VALUES (1, ?, ?)
                              ON DUPLICATE KEY UPDATE hook_secret=VALUES(hook_secret), updated_at=VALUES(updated_at)")
                   ->execute([random_hex(32), time()]);
                $flashMsg = '✅ 安装完成, 请用账号密码登录';
                $isFirstInstall = false;
                break;
            }

            // -- 登录 --
            case 'login': {
                $u = trim($_POST['username'] ?? '');
                $p = $_POST['password'] ?? '';
                $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
                $stmt->execute([$u]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($p, $row['password_hash'])) {
                    $flashErr = '❌ 用户名或密码错误';
                } else {
                    $_SESSION['admin_id'] = (int)$row['id'];
                    session_regenerate_id(true);
                    header('Location: admin.php'); exit;
                }
                break;
            }

            // -- 登出 --
            case 'logout': {
                $_SESSION = [];
                session_destroy();
                header('Location: admin.php'); exit;
            }

            // ============ 以下全部需要登录 ============

            // -- 改 hook_secret --
            case 'save_hook_secret': {
                $row = current_admin(); if (!$row) throw new Exception('请先登录');
                $v = trim($_POST['hook_secret'] ?? '');
                if (strlen($v) < 8) throw new Exception('HOOK_SECRET 至少 8 位');
                $db->prepare("INSERT INTO settings (id, hook_secret, updated_at) VALUES (1, ?, ?)
                              ON DUPLICATE KEY UPDATE hook_secret=VALUES(hook_secret), updated_at=VALUES(updated_at)")
                   ->execute([$v, time()]);
                $flashMsg = '✅ HOOK_SECRET 已保存';
                break;
            }

            // -- 重新生成 hook_secret --
            case 'regen_hook_secret': {
                $row = current_admin(); if (!$row) throw new Exception('请先登录');
                $new = random_hex(32);
                $db->prepare("INSERT INTO settings (id, hook_secret, updated_at) VALUES (1, ?, ?)
                              ON DUPLICATE KEY UPDATE hook_secret=VALUES(hook_secret), updated_at=VALUES(updated_at)")
                   ->execute([$new, time()]);
                $flashMsg = '✅ 新 HOOK_SECRET: ' . $new . ' (记得同步给 Worker)';
                break;
            }

            // -- 新建 API Key --
            case 'add_api_key': {
                $row = current_admin(); if (!$row) throw new Exception('请先登录');
                $name = trim($_POST['key_name'] ?? '');
                if (!$name) throw new Exception('给 key 起个名字');
                $key = 'mh_' . random_hex(24);
                $db->prepare("INSERT INTO api_keys (name, api_key, created_at) VALUES (?,?,?)")
                   ->execute([$name, $key, time()]);
                $flashMsg = '✅ 新 API Key: ' . $key . ' (只显示一次, 复制好)';
                break;
            }

            // -- 删 API Key --
            case 'del_api_key': {
                $row = current_admin(); if (!$row) throw new Exception('请先登录');
                $id = (int)($_POST['key_id'] ?? 0);
                $db->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
                $flashMsg = '✅ 已删除';
                break;
            }

            // -- 新建 admin --
            case 'add_admin': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $u = trim($_POST['new_username'] ?? '');
                $p = $_POST['new_password'] ?? '';
                if (strlen($u) < 2 || strlen($p) < 6) throw new Exception('用户名≥2位, 密码≥6位');
                $dup = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
                $dup->execute([$u]);
                if ($dup->fetchColumn() > 0) throw new Exception('用户名已存在');
                $hash = password_hash($p, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO admins (username, password_hash, created_at, updated_at) VALUES (?,?,?,?)")
                   ->execute([$u, $hash, time(), time()]);
                $flashMsg = '✅ 已创建: ' . $u;
                break;
            }

            // -- 改密码 (自己或别人) --
            case 'change_password': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $targetId = (int)($_POST['target_id'] ?? $me['id']);
                $newPass  = $_POST['new_password'] ?? '';
                if (strlen($newPass) < 6) throw new Exception('密码≥6位');
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare("UPDATE admins SET password_hash=?, updated_at=? WHERE id=?")
                   ->execute([$hash, time(), $targetId]);
                $flashMsg = '✅ 密码已更新';
                break;
            }

            // -- 改用户名 --
            case 'change_username': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $targetId = (int)($_POST['target_id'] ?? 0);
                $newName  = trim($_POST['new_username'] ?? '');
                if (!$targetId || strlen($newName) < 2) throw new Exception('参数错误');
                $dup = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = ? AND id != ?");
                $dup->execute([$newName, $targetId]);
                if ($dup->fetchColumn() > 0) throw new Exception('用户名已存在');
                $db->prepare("UPDATE admins SET username=?, updated_at=? WHERE id=?")
                   ->execute([$newName, time(), $targetId]);
                $flashMsg = '✅ 用户名已更新';
                break;
            }

            // -- 删 admin (不能删自己) --
            case 'del_admin': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $id = (int)($_POST['admin_id'] ?? 0);
                if ($id == $me['id']) throw new Exception('不能删自己');
                if ($id == 1) throw new Exception('不能删主 admin (id=1)');
                $db->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
                $flashMsg = '✅ 已删除';
                break;
            }

            // -- 加邮件域名 --
            case 'add_domain': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $d = trim($_POST['new_domain'] ?? '');
                $n = trim($_POST['domain_note'] ?? '');
                $d = rtrim($d, '.');
                if ($d === '' || strlen($d) > 128) throw new Exception('域名不能为空, 最长 128');
                if (!preg_match('/^[a-zA-Z0-9][-a-zA-Z0-9]*(\.[a-zA-Z0-9][-a-zA-Z0-9]*)+$/', $d))
                    throw new Exception('域名格式不正确, 如 example.com');
                $dup = $db->prepare("SELECT COUNT(*) FROM domains WHERE domain = ?");
                $dup->execute([$d]);
                if ($dup->fetchColumn() > 0) throw new Exception('域名已存在');
                $db->prepare("INSERT INTO domains (domain, note, created_at) VALUES (?,?,?)")
                   ->execute([$d, $n, time()]);
                $flashMsg = '✅ 已添加: ' . $d;
                break;
            }

            // -- 删邮件域名 --
            case 'del_domain': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $id = (int)($_POST['domain_id'] ?? 0);
                $db->prepare("DELETE FROM domains WHERE id = ?")->execute([$id]);
                $flashMsg = '✅ 已删除';
                break;
            }

            // -- 改域名备注 --
            case 'update_domain_note': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $id = (int)($_POST['domain_id'] ?? 0);
                $note = trim($_POST['domain_note'] ?? '');
                $db->prepare("UPDATE domains SET note=? WHERE id=?")->execute([$note, $id]);
                $flashMsg = '✅ 备注已更新';
                break;
            }

            // -- 更新域名 Resend 配置 (API Key + 每日额度) --
            case 'update_domain_resend': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $id = (int)($_POST['domain_id'] ?? 0);
                $key  = trim($_POST['resend_api_key'] ?? '');
                $limit = min(10000, max(1, (int)($_POST['daily_limit'] ?? 100)));
                $db->prepare("UPDATE domains SET resend_api_key=?, daily_limit=? WHERE id=?")
                   ->execute([$key ?: null, $limit, $id]);
                $flashMsg = '✅ Resend 配置已更新';
                break;
            }

            // -- 后台手动发送邮件 (走 Resend + 自动轮转) --
            case 'admin_send_mail': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $to      = trim($_POST['to']      ?? '');
                $subject = trim($_POST['subject'] ?? '');
                $html    = trim($_POST['html']    ?? '');
                $text    = trim($_POST['text']    ?? '');
                $fromEmail = trim($_POST['from_email'] ?? '');

                if ($to === '' || $subject === '') throw new Exception('收件人和主题必填');
                if ($html === '' && $text === '') throw new Exception('HTML 或 纯文本至少填一个');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('收件人邮箱格式不对');

                // 跨天清零
                $today = date('Y-m-d');
                $db->prepare("UPDATE domains SET today_count=0, count_date=? WHERE count_date<>? OR count_date IS NULL")
                   ->execute([$today, $today]);

                // 挑可用域名
                $preferDomain = '';
                if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                    $preferDomain = substr(strrchr($fromEmail, '@'), 1);
                }

                $domainRow = false;
                if ($preferDomain !== '') {
                    $stmt = $db->prepare("SELECT * FROM domains WHERE domain=? AND resend_api_key IS NOT NULL AND resend_api_key<>'' AND today_count < daily_limit LIMIT 1");
                    $stmt->execute([$preferDomain]);
                    $domainRow = $stmt->fetch();
                }
                if (!$domainRow) {
                    $domainRow = $db->query("SELECT * FROM domains WHERE resend_api_key IS NOT NULL AND resend_api_key<>'' AND today_count < daily_limit ORDER BY id ASC LIMIT 1")->fetch();
                }
                if (!$domainRow) throw new Exception('没有可用域名 (都没配 Resend Key 或今日额度用完了)');

                if ($fromEmail === '') $fromEmail = 'no-reply@' . $domainRow['domain'];

                // 调 Resend
                $payload = ['from'=>$fromEmail, 'to'=>[$to], 'subject'=>$subject];
                if ($html) $payload['html'] = $html;
                if ($text) $payload['text'] = $text;

                $ch = curl_init('https://api.resend.com/emails');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $domainRow['resend_api_key'], 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                ]);
                $raw = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);
                $resp = json_decode($raw, true);

                $ok = !$curlErr && $httpCode >= 200 && $httpCode < 300 && !empty($resp['id']);
                $resendId = $resp['id'] ?? '';
                $errMsg = $curlErr ?: ($resp['message'] ?? ($httpCode < 200 || $httpCode >= 300 ? "HTTP $httpCode" : ''));

                // 写日志
                $db->prepare(
                    "INSERT INTO sent_logs (domain_id, from_email, to_email, subject, html, plain_text, resend_id, status, error_msg, sent_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    (int)$domainRow['id'], $fromEmail, $to, $subject,
                    $html ?: null, $text ?: null, $resendId,
                    $ok ? 1 : 2, $errMsg, time(),
                ]);

                if ($ok) {
                    $db->prepare("UPDATE domains SET today_count = today_count + 1, count_date=? WHERE id=?")
                       ->execute([$today, $domainRow['id']]);
                    $flashMsg = "✅ 发送成功! 域名 {$domainRow['domain']} · Resend ID: {$resendId}";
                } else {
                    $flashErr = "❌ Resend 返回: " . ($errMsg ?: '未知错误');
                }
                break;
            }

            // -- 删邮件 --
            case 'del_mail': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $id = (int)($_POST['mail_id'] ?? 0);
                $db->prepare("DELETE FROM mails WHERE id = ?")->execute([$id]);
                $flashMsg = '✅ 已删除';
                break;
            }

            // -- 清空所有邮件 --
            case 'flush_mails': {
                $me = current_admin(); if (!$me) throw new Exception('请先登录');
                $db->exec("TRUNCATE TABLE mails");
                $flashMsg = '✅ 已清空所有邮件';
                break;
            }

            default:
                $flashErr = '未知操作: ' . htmlspecialchars($do);
        }
    } catch (Throwable $e) {
        $flashErr = '❌ ' . $e->getMessage();
    }
}

$do = $_POST['do'] ?? '';
// ---------- 登录状态判断 ----------
$loggedIn = check_admin_login();
$me = $loggedIn ? current_admin() : null;
if ($me === null && !$isFirstInstall && $do !== 'login') {
    $loggedIn = false;
}

// ---------- 页面查询参数 ----------
$tab     = $_GET['tab'] ?? 'mails';
$q       = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$pagesiz = min(100, max(10, (int)($_GET['pagesize'] ?? 30)));
$mailId  = (int)($_GET['mail_id'] ?? 0);

// ---------- 取渲染数据 ----------
$stats = ['total_mails'=>0, 'today_mails'=>0, 'admins'=>0, 'api_keys'=>0];
$mails = [];
$totalMails = 0;
$totalPages = 1;
$apiKeys = [];
$admins = [];
$hookSecret = '';
$detail = null;

if ($loggedIn) {
    $stats = [
        'total_mails'  => (int)$db->query("SELECT COUNT(*) FROM mails")->fetchColumn(),
        'today_mails'  => (int)$db->query("SELECT COUNT(*) FROM mails WHERE received_at >= UNIX_TIMESTAMP(CURDATE())")->fetchColumn(),
        'today_sent'   => (int)$db->query("SELECT COUNT(*) FROM sent_logs WHERE sent_at >= UNIX_TIMESTAMP(CURDATE()) AND status=1")->fetchColumn(),
        'admins'       => (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn(),
        'domains'      => (int)$db->query("SELECT COUNT(*) FROM domains")->fetchColumn(),
        'api_keys'     => (int)$db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn(),
    ];

    // 邮件列表 — 先查总数 (用于分页)
    $where = '';
    $args  = [];
    if ($q) {
        $where = " WHERE recipient LIKE ? OR subject LIKE ?";
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $countStmt = $db->prepare("SELECT COUNT(*) FROM mails" . $where);
    $countStmt->execute($args);
    $totalMails = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalMails / $pagesiz));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $pagesiz;

    // 再查当前页数据
    $sql = "SELECT id, recipient, subject, sender, received_at FROM mails"
         . $where
         . " ORDER BY id DESC LIMIT $pagesiz OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();   // ← 只 fetch 一次!
    foreach ($rows as &$r) {
        $r['received_date'] = date('Y-m-d H:i:s', (int)$r['received_at']);
    }
    $mails = $rows;

    // API Keys
    $apiKeys = $db->query("SELECT * FROM api_keys ORDER BY id DESC")->fetchAll();
    foreach ($apiKeys as &$k) {
        $k['created_date'] = date('Y-m-d H:i:s', (int)$k['created_at']);
        $k['last_used_date'] = $k['last_used_at'] ? date('Y-m-d H:i:s', (int)$k['last_used_at']) : '从未';
    }

    // Admins
    $admins = $db->query("SELECT id, username, created_at, updated_at FROM admins ORDER BY id ASC")->fetchAll();
    foreach ($admins as &$a) {
        $a['created_date'] = date('Y-m-d H:i:s', (int)$a['created_at']);
    }
    unset($a);

    // Domains — 先跨天清零
    $today = date('Y-m-d');
    $db->prepare("UPDATE domains SET today_count=0, count_date=? WHERE count_date<>? OR count_date IS NULL")
       ->execute([$today, $today]);

    $domains = $db->query("SELECT id, domain, note, resend_api_key, daily_limit, today_count, count_date, created_at FROM domains ORDER BY id ASC")->fetchAll();
    foreach ($domains as &$d) {
        $d['created_date'] = date('Y-m-d H:i:s', (int)$d['created_at']);
        $d['resend_key_mask'] = $d['resend_api_key'] ? substr($d['resend_api_key'], 0, 6) . '****' . substr($d['resend_api_key'], -4) : '';
        $d['has_resend'] = !empty($d['resend_api_key']);
        $d['percent']    = $d['daily_limit'] > 0 ? min(100, (int)$d['today_count'] * 100 / (int)$d['daily_limit']) : 0;
    }
    unset($d);

    // 最近发送日志 (给发送 tab 看)
    $stmt = $db->query("SELECT sl.*, d.domain
                         FROM sent_logs sl
                         LEFT JOIN domains d ON d.id = sl.domain_id
                         ORDER BY sl.id DESC LIMIT 50");
    $sentLogs = $stmt->fetchAll();
    foreach ($sentLogs as &$s) {
        $s['sent_date'] = date('Y-m-d H:i:s', (int)$s['sent_at']);
    }
    unset($s);

    // Hook secret
    $row = $db->query("SELECT hook_secret FROM settings WHERE id = 1")->fetch();
    $hookSecret = $row ? $row['hook_secret'] : '';

    // 邮件详情
    if ($mailId) {
        $stmt = $db->prepare("SELECT * FROM mails WHERE id = ?");
        $stmt->execute([$mailId]);
        $detail = $stmt->fetch();
        if ($detail) $detail['received_date'] = date('Y-m-d H:i:s', (int)$detail['received_at']);
    }
}

// =====================================================================
// HTML 渲染开始
// =====================================================================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📬 邮件路由管理后台</title>
<style>
:root{
  --bg:#f5f7fb;--surface:#fff;--surface-2:#f8fafc;--line:#e8edf3;
  --text:#172033;--muted:#718096;--primary:#5b5ce2;--primary-2:#4849c9;
  --success:#159570;--danger:#dc4b5b;--warning:#c98716;
  --shadow:0 10px 30px rgba(24,39,75,.06);--shadow-lg:0 20px 60px rgba(24,39,75,.14);
  --radius:16px;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
  margin:0;background:var(--bg);color:var(--text);
  font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC",
  "Hiragino Sans GB","Microsoft YaHei",Arial,sans-serif;font-size:14px;
}
a{color:inherit;text-decoration:none}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
::selection{background:rgba(91,92,226,.16)}

.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  min-height:38px;padding:0 13px;border:1px solid var(--line);border-radius:10px;
  background:var(--surface);color:var(--text);font-weight:600;font-size:13px;
  transition:.18s ease;white-space:nowrap;
}
.btn:hover{transform:translateY(-1px);border-color:#d5dbe5;background:#fbfcfe}
.btn:active{transform:none}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-2);border-color:var(--primary-2)}
.btn-danger{background:#fff4f5;border-color:#ffd7dc;color:var(--danger)}
.btn-danger:hover{background:#ffecef;border-color:#ffc2ca}
.btn-secondary{background:var(--surface-2);color:#536174}

input[type=text],input[type=password],textarea,select{
  width:100%;height:42px;padding:0 12px;border:1px solid var(--line);
  border-radius:10px;background:#fff;color:var(--text);outline:none;
  transition:.18s ease;
}
textarea{height:auto;padding:11px 12px}
input::placeholder{color:#a0aabb}
input:focus,textarea:focus,select:focus{
  border-color:rgba(91,92,226,.7);box-shadow:0 0 0 4px rgba(91,92,226,.10)
}
label{display:block;margin-bottom:7px;font-size:12px;font-weight:700;color:#4a5568}

.alert{
  display:flex;align-items:center;gap:9px;padding:12px 14px;border-radius:11px;
  margin-bottom:18px;font-size:13px;font-weight:600
}
.alert-ok{background:#eaf9f3;color:#087a5a;border:1px solid #ccefe2}
.alert-err{background:#fff0f2;color:#b52e42;border:1px solid #ffd7dd}

/* Login / install */
.login-wrap{
  min-height:100vh;display:grid;place-items:center;padding:28px;
  background:
    radial-gradient(circle at 15% 10%,rgba(91,92,226,.15),transparent 32%),
    radial-gradient(circle at 85% 85%,rgba(21,149,112,.10),transparent 28%),var(--bg)
}
.login-card{
  width:min(420px,100%);padding:34px;border:1px solid rgba(255,255,255,.75);
  border-radius:24px;background:rgba(255,255,255,.92);box-shadow:var(--shadow-lg);
  backdrop-filter:blur(16px)
}
.login-brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.brand-mark{
  width:46px;height:46px;border-radius:14px;display:grid;place-items:center;
  background:linear-gradient(135deg,#6b6ce9,#4b4cc8);color:#fff;font-size:22px;
  box-shadow:0 8px 18px rgba(91,92,226,.25)
}
.login-card h1{margin:0;font-size:23px;letter-spacing:-.4px}
.login-card p.sub{color:var(--muted);margin:6px 0 26px;line-height:1.6}
.login-card .field{margin-bottom:16px}
.login-card .btn{width:100%;height:44px;margin-top:6px}

/* App shell */
.app{min-height:100vh}
.topbar{
  position:sticky;top:0;z-index:50;height:68px;padding:0 30px;
  display:flex;align-items:center;justify-content:space-between;
  background:rgba(255,255,255,.88);border-bottom:1px solid rgba(232,237,243,.9);
  backdrop-filter:blur(18px)
}
.brand{display:flex;align-items:center;gap:11px}
.brand .brand-mark{width:36px;height:36px;border-radius:11px;font-size:17px}
.brand-copy strong{display:block;font-size:15px;line-height:1.1}
.brand-copy span{display:block;color:var(--muted);font-size:11px;margin-top:3px}
.user-area{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:12px}
.user-pill{
  display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--line);
  border-radius:10px;background:#fff
}
.avatar{
  width:26px;height:26px;border-radius:8px;display:grid;place-items:center;
  background:#eeefff;color:var(--primary);font-size:12px;font-weight:800
}

.tabs{
  display:flex;gap:4px;padding:10px 30px;background:#fff;border-bottom:1px solid var(--line);
  overflow:auto
}
.tab{
  position:relative;display:flex;align-items:center;gap:8px;padding:10px 14px;
  border-radius:10px;color:#6b778c;font-weight:650;white-space:nowrap;transition:.18s
}
.tab:hover{background:#f7f8fc;color:var(--text);text-decoration:none}
.tab.active{background:#f0f0ff;color:var(--primary)}
.tab.active:after{
  content:"";position:absolute;left:14px;right:14px;bottom:-11px;height:2px;
  background:var(--primary);border-radius:2px
}

.container{max-width:1320px;margin:0 auto;padding:28px 30px 50px}
.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-heading h2{margin:0;font-size:24px;letter-spacing:-.5px}
.page-heading p{margin:5px 0 0;color:var(--muted);font-size:12px}
.page-actions{display:flex;gap:8px}

.stats{
  display:grid;grid-template-columns:repeat(6,1fr);gap:15px;margin-bottom:22px
}
.stat-card{
  position:relative;overflow:hidden;padding:18px 19px;background:var(--surface);
  border:1px solid var(--line);border-radius:15px;box-shadow:var(--shadow)
}
.stat-card:after{
  content:"";position:absolute;right:-25px;bottom:-38px;width:100px;height:100px;
  border-radius:50%;background:rgba(91,92,226,.05)
}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.stat-icon{
  width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
  background:#f0f0ff;color:var(--primary);font-size:15px
}
.stat-card .num{font-size:27px;line-height:1;font-weight:800;letter-spacing:-.8px}
.stat-card .lbl{margin-top:8px;color:var(--muted);font-size:12px;font-weight:600}

.card{
  background:var(--surface);border:1px solid var(--line);border-radius:16px;
  margin-bottom:18px;box-shadow:var(--shadow);overflow:hidden
}
.card-header{
  min-height:62px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;
  gap:12px;border-bottom:1px solid var(--line);font-weight:750
}
.card-header .title{display:flex;align-items:center;gap:9px}
.card-header .actions{display:flex;gap:8px;align-items:center}
.card-body{padding:19px}

.toolbar{
  display:flex;align-items:center;gap:9px;margin-bottom:16px
}
.toolbar .search{position:relative;flex:1;max-width:420px}
.toolbar .search input{padding-left:37px}
.search-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#9aa5b5}
.toolbar select{width:auto;min-width:110px}

.table-wrap{overflow:auto}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
table th{
  text-align:left;padding:11px 12px;background:#fafbfc;color:#8591a3;
  font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  border-top:1px solid var(--line);border-bottom:1px solid var(--line)
}
table th:first-child{border-radius:9px 0 0 9px}
table th:last-child{border-radius:0 9px 9px 0}
table td{padding:13px 12px;border-bottom:1px solid #eef1f5;vertical-align:middle}
table tbody tr{transition:.15s}
table tbody tr:hover{background:#fafbff}
table tbody tr:last-child td{border-bottom:0}
.muted{color:var(--muted)}
.truncate{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.actions-cell{display:flex;align-items:center;gap:6px}
.actions-cell .btn{min-height:32px;padding:0 9px;font-size:12px}

.badge{
  display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;
  font-size:10px;font-weight:800
}
.badge-primary{background:#f0f0ff;color:var(--primary)}
.badge-success{background:#eaf9f3;color:#087a5a}
.badge-muted{background:#f1f3f6;color:#748094}
.badge-danger{background:#fff0f2;color:#b52e42}
.code{
  display:inline-block;max-width:100%;font-family:"SFMono-Regular",Consolas,Monaco,monospace;
  font-size:11px;background:#f4f6f9;color:#566275;padding:4px 7px;border-radius:7px;
  word-break:break-all
}
.key-code{cursor:pointer}

.empty{padding:56px 20px;text-align:center;color:var(--muted)}
.empty-icon{font-size:30px;margin-bottom:10px;opacity:.75}
.empty strong{display:block;color:#4a5568;margin-bottom:4px}

.detail-grid{
  display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px
}
.detail-item{padding:13px 14px;background:#fafbfc;border:1px solid var(--line);border-radius:11px}
.detail-item .label{font-size:11px;color:var(--muted);font-weight:700;margin-bottom:5px}
.detail-item .value{font-weight:650;word-break:break-word}
.detail-box{
  background:#111827;color:#dbe4f0;border:1px solid #202a3a;border-radius:11px;padding:15px;
  font-family:"SFMono-Regular",Consolas,Monaco,monospace;font-size:12px;line-height:1.65;
  white-space:pre-wrap;word-break:break-all;max-height:420px;overflow:auto
}
.setting-row{display:flex;gap:9px;align-items:center}
.setting-row input{font-family:Consolas,Monaco,monospace;font-size:12px}
.desc{color:var(--muted);font-size:12px;line-height:1.7;margin:6px 0 14px}

.pagination{
  display:flex;justify-content:space-between;align-items:center;gap:15px;margin-top:17px;
  font-size:12px;color:var(--muted)
}
.pagination-links{display:flex;gap:5px}
.pagination-links .btn{min-width:34px;height:34px;min-height:34px;padding:0 8px}

.modal-backdrop{
  position:fixed;inset:0;background:rgba(15,23,42,.48);display:flex;align-items:center;
  justify-content:center;z-index:100;padding:20px;backdrop-filter:blur(4px)
}
.modal{
  background:#fff;border-radius:18px;padding:24px;width:min(430px,100%);
  box-shadow:var(--shadow-lg);border:1px solid rgba(255,255,255,.8)
}
.modal h3{margin:0 0 18px;font-size:17px}
.modal .field{margin-bottom:15px}
.modal-close{
  float:right;width:32px;height:32px;border:0;border-radius:8px;background:#f5f6f8;
  color:#778297;font-size:19px;line-height:1
}

@media(max-width:980px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .container{padding:22px 18px 40px}.topbar{padding:0 18px}.tabs{padding:9px 18px}
}
@media(max-width:680px){
  .topbar{height:auto;min-height:64px;padding:11px 14px}
  .brand-copy span{display:none}.user-area>span{display:none}
  .tabs{padding:8px 10px}.tab{padding:9px 11px;font-size:12px}
  .container{padding:16px 12px 30px}
  .page-heading{align-items:flex-start;flex-direction:column}
  .stats{grid-template-columns:1fr 1fr;gap:10px}
  .stat-card{padding:14px}.stat-card .num{font-size:22px}
  .card-body{padding:13px}.card-header{padding:12px 13px}
  .toolbar{flex-wrap:wrap}.toolbar .search{max-width:none;flex-basis:100%}.toolbar select{flex:1}
  .detail-grid{grid-template-columns:1fr}
  .setting-row{flex-wrap:wrap}.setting-row input{flex-basis:100%}
  .actions-cell{flex-wrap:wrap}
  .login-card{padding:25px}
}
</style>
</head>
<body>

<?php if ($isFirstInstall): ?>
<!-- ========== 首次安装 ========== -->
<div class="login-wrap">
  <div class="login-card">
    <h1>🚀 首次安装</h1>
    <p class="sub">创建第一个管理员账号, 初始化 HOOK_SECRET</p>
    <?php if ($flashErr): ?><div class="alert alert-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>
    <?php if ($flashMsg): ?><div class="alert alert-ok"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="do" value="install">
      <div class="field">
        <label>用户名</label>
        <input type="text" name="username" required minlength="2" autofocus>
      </div>
      <div class="field">
        <label>密码 (≥6位)</label>
        <input type="password" name="password" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary">创建账号 →</button>
    </form>
  </div>
</div>

<?php elseif (!$loggedIn): ?>
<!-- ========== 登录 ========== -->
<div class="login-wrap">
  <div class="login-card">
    <h1>📬 邮件路由后台</h1>
    <p class="sub">登录后可管理邮件、密钥、API Key</p>
    <?php if ($flashErr): ?><div class="alert alert-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="do" value="login">
      <div class="field">
        <label>用户名</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="field">
        <label>密码</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary">登录</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ========== 主界面 ========== -->

<div class="app">
<div class="topbar">
  <div class="brand">
    <div class="brand-mark">✉</div>
    <div class="brand-copy"><strong>MailHook</strong><span>邮件路由管理后台</span></div>
  </div>
  <div class="user-area">
    <div class="user-pill"><span class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($me['username'],0,1))) ?></span><b><?= htmlspecialchars($me['username']) ?></b></div>
    <form method="POST" style="display:inline" onsubmit="return confirm('确认登出?')">
      <input type="hidden" name="do" value="logout">
      <button type="submit" class="btn btn-secondary">🚪 登出</button>
    </form>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $tab==='mails'?'active':'' ?>" href="?tab=mails">📬 邮件</a>
  <a class="tab <?= $tab==='domains'?'active':'' ?>" href="?tab=domains">🌐 域名</a>
  <a class="tab <?= $tab==='send'?'active':'' ?>" href="?tab=send">📤 发送</a>
  <a class="tab <?= $tab==='keys'?'active':'' ?>" href="?tab=keys">🔑 API Keys</a>
  <a class="tab <?= $tab==='admins'?'active':'' ?>" href="?tab=admins">👤 管理员</a>
  <a class="tab <?= $tab==='settings'?'active':'' ?>" href="?tab=settings">⚙️ 系统设置</a>
</div>

<div class="container">

<div class="page-heading">
  <div>
    <h2><?php
      $titles = ['mails'=>'邮件中心','domains'=>'邮件域名','send'=>'发送邮件','keys'=>'API Keys','admins'=>'管理员','settings'=>'系统设置'];
      echo htmlspecialchars($titles[$tab] ?? '控制台');
    ?></h2>
    <p>集中管理邮件、访问密钥与系统配置</p>
  </div>
</div>

<?php if ($flashMsg): ?><div class="alert alert-ok"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<!-- 统计 -->
<div class="stats">
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">✉</span><span class="badge badge-primary">MAIL</span></div><div class="num"><?= $stats['total_mails'] ?></div><div class="lbl">累计邮件</div></div>
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">◷</span><span class="badge badge-success">TODAY</span></div><div class="num"><?= $stats['today_mails'] ?></div><div class="lbl">今日收到</div></div>
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">📤</span><span class="badge badge-primary">SENT</span></div><div class="num"><?= $stats['today_sent'] ?></div><div class="lbl">今日发送</div></div>
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">⌁</span><span class="badge badge-muted">KEYS</span></div><div class="num"><?= $stats['api_keys'] ?></div><div class="lbl">API Keys</div></div>
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">🌐</span><span class="badge badge-muted">DOMAINS</span></div><div class="num"><?= $stats['domains'] ?></div><div class="lbl">邮件域名</div></div>
  <div class="stat-card"><div class="stat-top"><span class="stat-icon">♙</span><span class="badge badge-muted">ADMIN</span></div><div class="num"><?= $stats['admins'] ?></div><div class="lbl">管理员</div></div>
</div>

<?php if ($tab === 'mails'): ?>
<!-- ============ 邮件列表 ============ -->
<div class="card">
  <div class="card-header">
    <div class="title">📬 邮件列表</div>
    <div class="actions">
      <a href="?tab=mails" class="btn btn-secondary">🔄 全部</a>
      <form method="POST" style="display:inline" onsubmit="return confirm('⚠️ 确认清空所有邮件? 不可恢复!')">
        <input type="hidden" name="do" value="flush_mails">
        <button type="submit" class="btn btn-danger">清空全部</button>
      </form>
    </div>
  </div>
  <div class="card-body">
    <form method="GET" class="toolbar">
      <input type="hidden" name="tab" value="mails">
      <input type="hidden" name="pagesize" value="<?= $pagesiz ?>">
      <div class="search">
        <span class="search-icon">⌕</span>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜索收件人或主题...">
      </div>
      <button type="submit" class="btn btn-primary">搜索</button>
      <?php if ($q): ?>
        <a href="?tab=mails" class="btn btn-secondary">清除</a>
      <?php endif; ?>
      <div style="flex:1"></div>
      <select name="pagesize" onchange="this.form.submit()">
        <?php foreach ([20,30,50,100] as $ps): ?>
          <option value="<?= $ps ?>" <?= $pagesiz===$ps?'selected':'' ?>><?= $ps ?> 条/页</option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if (!$mails): ?>
      <div class="empty">📭 还没有邮件, 等 Worker 推送过来吧</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr>
        <th style="width:60px">ID</th>
        <th>收件人</th>
        <th>主题</th>
        <th>发件人</th>
        <th style="width:150px">时间</th>
        <th style="width:160px">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($mails as $m): ?>
      <tr>
        <td><?= $m['id'] ?></td>
        <td><b><?= htmlspecialchars($m['recipient']) ?></b></td>
        <td title="<?= htmlspecialchars($m['subject'] ?? '') ?>">
          <?= htmlspecialchars(mb_substr($m['subject'] ?? '(无主题)', 0, 40)) ?>
        </td>
        <td style="color:var(--muted)"><?= htmlspecialchars(mb_substr($m['sender'] ?? '', 0, 30)) ?></td>
        <td style="color:var(--muted);font-size:12px"><?= $m['received_date'] ?></td>
        <td>
          <a href="?tab=mails&mail_id=<?= $m['id'] ?>" class="btn btn-secondary" style="padding:4px 10px;font-size:12px">👁 详情</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('删除这封邮件?')">
            <input type="hidden" name="do" value="del_mail">
            <input type="hidden" name="mail_id" value="<?= $m['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <?php if ($totalPages > 1): ?>
    <!-- 分页器 -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:13px">
      <div style="color:var(--muted)">
        共 <b style="color:var(--text)"><?= $totalMails ?></b> 条 ·
        第 <b style="color:var(--text)"><?= $page ?></b> / <?= $totalPages ?> 页
      </div>
      <div style="display:flex;gap:4px">
        <?php
        // 构建分页链接参数
        $pageLink = function($p) use ($q, $pagesiz) {
            $qs = http_build_query(['tab'=>'mails','q'=>$q,'page'=>$p,'pagesize'=>$pagesiz]);
            return '?' . $qs;
        };
        // 页码范围
        $startP = max(1, $page - 2);
        $endP   = min($totalPages, $page + 2);
        ?>
        <?php if ($page > 1): ?>
          <a href="<?= $pageLink(1) ?>" class="btn btn-secondary" style="padding:5px 10px">«</a>
          <a href="<?= $pageLink($page-1) ?>" class="btn btn-secondary" style="padding:5px 10px">‹</a>
        <?php endif; ?>
        <?php for ($i = $startP; $i <= $endP; $i++): ?>
          <?php if ($i == $page): ?>
            <span class="btn btn-primary" style="padding:5px 10px;cursor:default"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= $pageLink($i) ?>" class="btn btn-secondary" style="padding:5px 10px"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?= $pageLink($page+1) ?>" class="btn btn-secondary" style="padding:5px 10px">›</a>
          <a href="<?= $pageLink($totalPages) ?>" class="btn btn-secondary" style="padding:5px 10px">»</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php if ($detail): ?>
<div class="card">
  <div class="card-header">
    📄 邮件详情 #<?= $detail['id'] ?> — <?= htmlspecialchars($detail['recipient']) ?>
    <a href="?tab=mails" class="btn btn-secondary">← 关闭</a>
  </div>
  <div class="card-body">
    <div class="detail-grid">
      <div class="detail-item"><div class="label">收件人</div><div class="value"><?= htmlspecialchars($detail['recipient']) ?></div></div>
      <div class="detail-item"><div class="label">主题</div><div class="value"><?= htmlspecialchars($detail['subject'] ?? '(无)') ?></div></div>
      <div class="detail-item"><div class="label">发件人</div><div class="value"><?= htmlspecialchars($detail['sender'] ?? '(无)') ?></div></div>
      <div class="detail-item"><div class="label">接收时间</div><div class="value"><?= $detail['received_date'] ?></div></div>
    </div>
    <p><b>Body</b></p>
    <div class="detail-box"><?= htmlspecialchars($detail['body'] ?? '(空)') ?></div>
    <?php if (!empty($detail['html'])): ?>
      <p style="margin-top:14px"><b>HTML:</b></p>
      <div class="detail-box" style="max-height:200px"><?= htmlspecialchars(mb_substr($detail['html'],0,500)) ?><?= mb_strlen($detail['html'])>500?'... (已截断)':'' ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'domains'): ?>
<!-- ============ 邮件域名 ============ -->
<div class="card">
  <div class="card-header">
    <div class="title">🌐 可用邮件域名 + 📨 Resend 发信</div>
    <button onclick="document.getElementById('add-domain-modal').style.display='flex'" class="btn btn-primary">➕ 添加域名</button>
  </div>
  <div class="card-body">
    <?php if (!$domains): ?>
      <div class="empty">还没有域名, 点右上角添加一个</div>
    <?php else: ?>

    <div class="desc" style="margin-bottom:14px">
      注册邮箱格式: <code>前缀@域名</code> · 发送邮件时系统自动挑今日额度没用完的域名轮转
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px">
      <?php foreach ($domains as $d): ?>
      <span class="code key-code"
            onclick="copyText('<?= htmlspecialchars($d['domain']) ?>', this)"
            title="点击复制完整域名">@<?= htmlspecialchars($d['domain']) ?>
        <?php if ($d['has_resend']): ?>
          <span style="color:var(--success)">●</span>
        <?php else: ?>
          <span style="color:#c98716">○</span>
        <?php endif; ?>
      </span>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap"><table>
      <thead><tr>
        <th style="width:50px">ID</th>
        <th>域名</th>
        <th>备注</th>
        <th>Resend API Key</th>
        <th style="width:200px">今日额度</th>
        <th style="width:80px">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($domains as $d): ?>
      <tr>
        <td><?= $d['id'] ?></td>
        <td><b><?= htmlspecialchars($d['domain']) ?></b></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center"
                onsubmit="updateDomainNote(<?= $d['id'] ?>, this);return false">
            <input type="hidden" name="do" value="update_domain_note">
            <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
            <input type="text" name="domain_note" value="<?= htmlspecialchars($d['note'] ?? '') ?>"
                   placeholder="备注"
                   style="height:32px;padding:0 10px;font-size:12px;width:150px;border-radius:8px;border:1px solid var(--line)">
            <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px">💾</button>
          </form>
        </td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"
                onsubmit="return confirm('确认保存 Resend 配置?')">
            <input type="hidden" name="do" value="update_domain_resend">
            <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
            <input type="text" name="resend_api_key"
                   value="<?= htmlspecialchars($d['resend_api_key'] ?? '') ?>"
                   placeholder="re_xxxxxxxxxxxxx"
                   style="height:32px;padding:0 10px;font-size:12px;width:200px;border-radius:8px;border:1px solid <?= $d['has_resend'] ? 'var(--success)' : 'var(--line)' ?>;font-family:Consolas,monospace">
            <input type="number" name="daily_limit" value="<?= (int)$d['daily_limit'] ?>" min="1" max="10000"
                   title="每日免费额度"
                   style="height:32px;padding:0 6px;font-size:12px;width:60px;border-radius:8px;border:1px solid var(--line)">
            <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px">💾</button>
          </form>
        </td>
        <td>
          <?php if ($d['has_resend']): ?>
            <?php
              $color = $d['percent'] >= 95 ? 'var(--danger)' : ($d['percent'] >= 70 ? 'var(--warning)' : 'var(--success)');
            ?>
            <div style="font-size:11px;color:var(--muted);margin-bottom:4px">
              <b style="color:<?= $color ?>;font-size:13px"><?= (int)$d['today_count'] ?></b> / <?= (int)$d['daily_limit'] ?>
              <span class="badge badge-<?= $d['percent'] >= 95 ? 'danger' : ($d['percent'] >= 70 ? 'primary' : 'success') ?>" style="margin-left:4px">
                <?= (int)$d['percent'] ?>%
              </span>
            </div>
            <div style="height:6px;background:#eeefff;border-radius:4px;overflow:hidden">
              <div style="height:100%;width:<?= (int)$d['percent'] ?>%;background:<?= $color ?>;transition:.2s"></div>
            </div>
          <?php else: ?>
            <span style="color:var(--muted);font-size:12px">未配置</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="POST" onsubmit="return confirm('删除域名 <?= htmlspecialchars($d['domain']) ?>?')">
            <input type="hidden" name="do" value="del_domain">
            <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- 新建域名弹窗 -->
<div id="add-domain-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('add-domain-modal').style.display='none'">×</button>
    <h3>➕ 添加邮件域名</h3>
    <form method="POST">
      <input type="hidden" name="do" value="add_domain">
      <div class="field">
        <label>域名 (如 example.com)</label>
        <input type="text" name="new_domain" required placeholder="example.com">
      </div>
      <div class="field">
        <label>备注 (可选)</label>
        <input type="text" name="domain_note" placeholder="比如: 主力域名 / 备用">
      </div>
      <button type="submit" class="btn btn-primary">添加</button>
    </form>
  </div>
</div>

<script>
async function updateDomainNote(id, form) {
  const fd = new FormData(form);
  const resp = await fetch(window.location.pathname + '?tab=domains', {
    method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  if (resp.ok) location.reload();
  else alert('❌ 更新失败');
}
</script>

<?php elseif ($tab === 'send'): ?>
<!-- ============ 📤 发送邮件 ============ -->
<div class="card">
  <div class="card-header">
    <div class="title">📤 手动发送邮件 <span class="badge badge-success">Resend</span></div>
    <a href="?tab=send" class="btn btn-secondary" style="padding:6px 12px;font-size:12px">🔄 刷新日志</a>
  </div>
  <div class="card-body">

    <form method="POST" style="max-width:640px;margin-bottom:28px">
      <input type="hidden" name="do" value="admin_send_mail">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="field" style="margin-bottom:0">
          <label>发件人 (可选, 默认 no-reply@域名)</label>
          <input type="text" name="from_email" placeholder="no-reply@example.com">
        </div>
        <div class="field" style="margin-bottom:0">
          <label>收件人 <span style="color:var(--danger)">*</span></label>
          <input type="text" name="to" required placeholder="target@example.com">
        </div>
      </div>
      <div class="field">
        <label>主题 <span style="color:var(--danger)">*</span></label>
        <input type="text" name="subject" required placeholder="邮件主题">
      </div>
      <div class="field">
        <label>HTML 正文 (二选一)</label>
        <textarea name="html" rows="6" placeholder="<p>Hello, <b>world</b>!</p>"></textarea>
      </div>
      <div class="field">
        <label>纯文本正文 (可选)</label>
        <textarea name="text" rows="4" placeholder="Hello, world!"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="min-width:160px">📤 发送 (自动选域名)</button>
      <span class="muted" style="margin-left:10px;font-size:12px">
        系统会自动挑一个配了 Resend Key 且今日额度未满的域名发送
      </span>
    </form>

    <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">

    <h3 style="margin:0 0 12px;font-size:15px">📋 最近 50 条发送记录</h3>

    <?php if (empty($sentLogs)): ?>
      <div class="empty">还没有发送记录</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr>
        <th style="width:60px">ID</th>
        <th>状态</th>
        <th>域名</th>
        <th>发件人</th>
        <th>收件人</th>
        <th>主题</th>
        <th style="width:160px">时间</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sentLogs as $s): ?>
      <tr>
        <td><?= $s['id'] ?></td>
        <td>
          <?php if ((int)$s['status'] === 1): ?>
            <span class="badge badge-success">✅ 成功</span>
          <?php elseif ((int)$s['status'] === 2): ?>
            <span class="badge badge-danger" title="<?= htmlspecialchars($s['error_msg'] ?? '') ?>">❌ 失败</span>
          <?php else: ?>
            <span class="badge badge-muted">⏳ 待发</span>
          <?php endif; ?>
        </td>
        <td><b><?= htmlspecialchars($s['domain'] ?? '(已删除)') ?></b></td>
        <td class="truncate"><?= htmlspecialchars($s['from_email']) ?></td>
        <td class="truncate"><?= htmlspecialchars($s['to_email']) ?></td>
        <td class="truncate" title="<?= htmlspecialchars($s['subject'] ?? '') ?>"><?= htmlspecialchars(mb_substr($s['subject'] ?? '(无主题)', 0, 40)) ?></td>
        <td style="color:var(--muted);font-size:12px"><?= $s['sent_date'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'keys'): ?>
<!-- ============ API Keys ============ -->
<div class="card">
  <div class="card-header">
    <div class="title">🔑 API Keys <span class="badge badge-muted">访问凭证</span></div>
    <button onclick="document.getElementById('add-key-modal').style.display='flex'" class="btn btn-primary">➕ 新建 Key</button>
  </div>
  <div class="card-body">
    <?php if (!$apiKeys): ?>
      <div class="empty">还没有 API Key, 点右上角新建一个 (注册脚本会用到)</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr>
        <th>ID</th><th>名称</th><th>Key</th><th>创建</th><th>最后使用</th><th>操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($apiKeys as $k): ?>
      <tr>
        <td><?= $k['id'] ?></td>
        <td><b><?= htmlspecialchars($k['name']) ?></b></td>
        <td class="code" style="cursor:pointer" onclick="copyText('<?= htmlspecialchars($k['api_key']) ?>', this)" title="点击复制">
          <?= htmlspecialchars(substr($k['api_key'],0,24)) ?>***
        </td>
        <td style="color:var(--muted);font-size:12px"><?= $k['created_date'] ?></td>
        <td style="color:var(--muted);font-size:12px"><?= $k['last_used_date'] ?></td>
        <td>
          <form method="POST" onsubmit="return confirm('删除这个 API Key? 用这个 key 的脚本会立即失效')">
            <input type="hidden" name="do" value="del_api_key">
            <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px">🗑 删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- 新建 API Key 弹窗 -->
<div id="add-key-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('add-key-modal').style.display='none'">×</button>
    <h3>➕ 新建 API Key</h3>
    <form method="POST">
      <input type="hidden" name="do" value="add_api_key">
      <div class="field">
        <label>Key 名称 (用途标识)</label>
        <input type="text" name="key_name" placeholder="比如: mowan_register" required>
      </div>
      <button type="submit" class="btn btn-primary">生成</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'admins'): ?>
<!-- ============ 管理员 ============ -->
<div class="card">
  <div class="card-header">
    <div class="title">👤 管理员</div>
    <button onclick="document.getElementById('add-admin-modal').style.display='flex'" class="btn btn-primary">➕ 新建管理员</button>
  </div>
  <div class="card-body">
    <div class="table-wrap"><table>
      <thead><tr>
        <th>ID</th><th>用户名</th><th>创建</th><th>密码</th><th>操作</th><th>删除</th>
      </tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
      <tr>
        <td><?= $a['id'] ?></td>
        <td><b><?= htmlspecialchars($a['username']) ?></b><?= $a['id']==1?' <span style="color:#dc2626;font-size:11px">主</span>':'' ?><?= $a['id']==$me['id']?' <span style="color:var(--success);font-size:11px">我</span>':'' ?></td>
        <td style="color:var(--muted);font-size:12px"><?= $a['created_date'] ?></td>
        <td>
          <button onclick="openPwdModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['username']) ?>')" class="btn btn-secondary" style="padding:4px 10px;font-size:12px">🔐 改密码</button>
        </td>
        <td>
          <button onclick="openNameModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['username']) ?>')" class="btn btn-secondary" style="padding:4px 10px;font-size:12px">✏️ 改名</button>
        </td>
        <td>
          <?php if ($a['id'] != 1 && $a['id'] != $me['id']): ?>
          <form method="POST" onsubmit="return confirm('确认删除 <?= htmlspecialchars($a['username']) ?>?')">
            <input type="hidden" name="do" value="del_admin">
            <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px">🗑</button>
          </form>
          <?php else: ?>
          <span style="color:var(--muted);font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<!-- 新建管理员弹窗 -->
<div id="add-admin-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('add-admin-modal').style.display='none'">×</button>
    <h3>➕ 新建管理员</h3>
    <form method="POST">
      <input type="hidden" name="do" value="add_admin">
      <div class="field">
        <label>用户名</label>
        <input type="text" name="new_username" required minlength="2">
      </div>
      <div class="field">
        <label>密码 (≥6位)</label>
        <input type="password" name="new_password" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary">创建</button>
    </form>
  </div>
</div>

<!-- 改密码弹窗 -->
<div id="pwd-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('pwd-modal').style.display='none'">×</button>
    <h3>🔐 修改 <span id="pwd-target-name"></span> 的密码</h3>
    <form method="POST">
      <input type="hidden" name="do" value="change_password">
      <input type="hidden" name="target_id" id="pwd-target-id">
      <div class="field">
        <label>新密码 (≥6位)</label>
        <input type="password" name="new_password" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary">保存</button>
    </form>
  </div>
</div>

<!-- 改名弹窗 -->
<div id="name-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('name-modal').style.display='none'">×</button>
    <h3>✏️ 修改 <span id="name-target-name"></span> 的用户名</h3>
    <form method="POST">
      <input type="hidden" name="do" value="change_username">
      <input type="hidden" name="target_id" id="name-target-id">
      <div class="field">
        <label>新用户名</label>
        <input type="text" name="new_username" id="name-input" required minlength="2">
      </div>
      <button type="submit" class="btn btn-primary">保存</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'settings'): ?>
<!-- ============ 系统设置 ============ -->
<div class="card">
  <div class="card-header">⚙️ 系统设置</div>
  <div class="card-body">
    <h3 style="margin-top:0">🔑 Worker 回调用密钥 HOOK_SECRET</h3>
    <p class="desc">Cloudflare Worker 收到邮件后用这个值做鉴权。Worker 端也要同步设置: <code>wrangler secret put HOOK_SECRET</code></p>
    <form method="POST">
      <input type="hidden" name="do" value="save_hook_secret">
      <div class="setting-row">
        <input type="text" name="hook_secret" value="<?= htmlspecialchars($hookSecret) ?>" required minlength="8">
        <button type="button" class="btn btn-secondary" onclick="copyText('<?= htmlspecialchars($hookSecret) ?>')">📋 复制</button>
        <button type="submit" class="btn btn-primary">💾 保存</button>
      </div>
    </form>
    <form method="POST" style="margin-top:10px" onsubmit="return confirm('重新生成后记得同步给 Worker!')">
      <input type="hidden" name="do" value="regen_hook_secret">
      <button type="submit" class="btn btn-danger">🔄 重新生成</button>
    </form>
  </div>
</div>

<?php endif; ?>

</div>

<script>
function copyText(text, el) {
  navigator.clipboard.writeText(text).then(() => {
    const old = el ? el.textContent : '';
    if (el) { el.textContent = '✅ 已复制'; setTimeout(()=>el.textContent = old, 1500); }
    else alert('✅ 已复制到剪贴板');
  });
}
function openPwdModal(id, name) {
  document.getElementById('pwd-target-id').value = id;
  document.getElementById('pwd-target-name').textContent = name;
  document.getElementById('pwd-modal').style.display = 'flex';
}
function openNameModal(id, name) {
  document.getElementById('name-target-id').value = id;
  document.getElementById('name-target-name').textContent = name;
  document.getElementById('name-input').value = name;
  document.getElementById('name-modal').style.display = 'flex';
}
document.addEventListener('keydown', function(e){
  if(e.key !== 'Escape') return;
  document.querySelectorAll('.modal-backdrop').forEach(function(m){m.style.display='none';});
});
</script>
<?php endif; /* end logged-in */ ?>
</div>

<!-- Footer -->
<div style="text-align:center;padding:28px 0 40px;color:#9aa5b5;font-size:12px">
  <a href="https://lygalaxy.cn/" target="_blank" rel="noopener" style="color:#9aa5b5;text-decoration:none">
    MailHook &nbsp;·&nbsp; by <b>冷月笙寒-Galaxy</b>
  </a>
</div>

</body>
</html>

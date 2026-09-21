<?php
// =====================================================================
// 数据库连接 + 通用辅助函数
// =====================================================================

if (!defined('IN_MAILHOOK')) die('no access');
require_once __DIR__ . '/config.php';

/** 全局 PDO 实例 (单例) */
function get_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    try {
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $db->exec("SET NAMES utf8mb4");
    } catch (PDOException $e) {
        http_response_code(500);
        exit(json_encode(['code'=>500, 'message'=>'DB connect failed: '.$e->getMessage()]));
    }
    return $db;
}

/** 启动 Session (后台用) */
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

/** 后台登录校验 — 未登录返回 false */
function check_admin_login(): bool {
    start_session();
    return !empty($_SESSION['admin_id']);
}

/** 获取当前登录管理员信息 (数组或 null) */
function current_admin(): ?array {
    if (!check_admin_login()) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $db = get_db();
    $stmt = $db->prepare("SELECT id, username FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

/** 读 settings 表的 hook_secret (单行 id=1) */
function get_hook_secret(): string {
    $db = get_db();
    $row = $db->query("SELECT hook_secret FROM settings WHERE id = 1")->fetch();
    return $row ? $row['hook_secret'] : '';
}

/** 生成随机 hex 串 */
function random_hex(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/** 统一 JSON 输出 */
function json_resp(int $code, array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 注册脚本 api_key 鉴权 — 从 Header 读 Api-Key */
function check_api_key(): void {
    $key = $_SERVER['HTTP_API_KEY']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? '';
    // 兼容 Authorization: Bearer xxx 格式
    if (stripos($key, 'Bearer ') === 0) {
        $key = trim(substr($key, 7));
    }
    if (!$key) {
        json_resp(401, ['message' => 'missing Api-Key header'], 401);
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        json_resp(401, ['message' => 'invalid api key'], 401);
    }
    // 更新 last_used_at
    $db->prepare("UPDATE api_keys SET last_used_at = ? WHERE id = ?")
       ->execute([time(), $row['id']]);
}

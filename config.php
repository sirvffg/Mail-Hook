<?php
// =====================================================================
// MailHook — 邮件接收/查询/转发系统
// Author: 冷月笙寒-Galaxy
// Site:   https://lygalaxy.cn/
// =====================================================================
// 系统配置 — 数据库连接 + Session
// 直接访问此文件会 404 (靠 db.php 里判断 DIRECTORY_SEPARATOR 不行), 
// 所以通过 db.php 里的 defined 判断阻止外部直访
// =====================================================================

defined('IN_MAILHOOK') || define('IN_MAILHOOK', true);

// ---- MySQL ----
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'email');
define('DB_USER', 'email');
define('DB_PASS', 'XXXXXXXXX');

// ---- Session ----
define('SESSION_NAME', 'mailhook_admin');

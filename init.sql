-- =====================================================================
-- Mail Hook 初始化脚本
-- 用途: 新环境一键建表, 不带任何业务数据
-- 执行: mysql -u user -p dbname < init.sql
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- 自动 commit 幂等, 可重复执行

-- =====================================================================
-- 1. admins — 管理员账号
-- =====================================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)      NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL COMMENT 'password_hash(PASSWORD_BCRYPT)',
  `created_at`    INT(10) UNSIGNED NOT NULL,
  `updated_at`    INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 2. api_keys — 外部注册脚本访问凭证
-- =====================================================================
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(64)      NOT NULL COMMENT '用途标识, 比如 mowan_register',
  `api_key`      VARCHAR(128)     NOT NULL COMMENT '格式: mh_ + 48位hex',
  `last_used_at` INT(10) UNSIGNED DEFAULT NULL,
  `created_at`   INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 3. domains — 邮件域名池 + Resend 发信配置
-- =====================================================================
CREATE TABLE IF NOT EXISTS `domains` (
  `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain`          VARCHAR(128)     NOT NULL COMMENT '完整域名, 如 example.com',
  `note`            VARCHAR(255)     DEFAULT NULL COMMENT '备注',
  `resend_api_key`  VARCHAR(128)     DEFAULT NULL COMMENT 'Resend API Key, 格式 re_xxxxxxx',
  `daily_limit`     INT(10) UNSIGNED NOT NULL DEFAULT 100 COMMENT '每日免费额度, Resend 免费版是 100',
  `today_count`     INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '今日已发送数量',
  `count_date`      DATE             DEFAULT NULL COMMENT 'today_count 对应的日期, 跨天自动清零',
  `created_at`      INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 4. mails — Worker 推送过来的邮件
-- =====================================================================
CREATE TABLE IF NOT EXISTS `mails` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient`   VARCHAR(128)        NOT NULL,
  `subject`     VARCHAR(512)        DEFAULT NULL,
  `sender`      VARCHAR(256)        DEFAULT NULL,
  `body`        TEXT,
  `html`        MEDIUMTEXT,
  `raw`         MEDIUMTEXT,
  `received_at` INT(10) UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient`),
  KEY `idx_received`  (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 5. sent_logs — Resend 发信日志
-- =====================================================================
CREATE TABLE IF NOT EXISTS `sent_logs` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id`   INT(10) UNSIGNED    NOT NULL,
  `from_email`  VARCHAR(255)        NOT NULL,
  `to_email`    VARCHAR(255)        NOT NULL,
  `subject`     VARCHAR(512)        DEFAULT NULL,
  `html`        MEDIUMTEXT,
  `plain_text`  TEXT,
  `resend_id`   VARCHAR(128)        DEFAULT NULL COMMENT 'Resend 返回的邮件 ID',
  `status`      TINYINT(4)          NOT NULL DEFAULT 0 COMMENT '0=待发送 1=成功 2=失败',
  `error_msg`   VARCHAR(1024)       DEFAULT NULL COMMENT '失败原因',
  `sent_at`     INT(10) UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_to`     (`to_email`),
  KEY `idx_sent`   (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发送邮件日志';

-- =====================================================================
-- 6. settings — 单行全局配置
-- =====================================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`          TINYINT(3) UNSIGNED NOT NULL,
  `hook_secret` VARCHAR(128)        NOT NULL COMMENT 'Worker 回调用密钥',
  `updated_at`  INT(10) UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 写入默认 settings 行 (admin.php 的首次安装会自动更新 hook_secret)
INSERT INTO `settings` (`id`, `hook_secret`, `updated_at`)
VALUES (1, '', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `hook_secret` = VALUES(`hook_secret`),
  `updated_at`  = VALUES(`updated_at`);

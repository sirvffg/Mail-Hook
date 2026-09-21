# MailHook

Cloudflare Email Routing → Worker → PHP → MySQL 的邮件接收/查询/转发系统。专为批量注册、临时邮箱等场景设计，支持 Resend 自动轮转发信。

##  功能特性

- **邮件接收** — Cloudflare Worker 实时转发，PHP 端 MIME 解析入库
- **API 查询** — 按邮箱前缀/完整地址查最新邮件，自动提取 4-6 位验证码
- **Resend 发信** — 多域名 + 多 Resend Key 自动轮转，每日额度独立计数
- **API Key 鉴权** — 给注册脚本用的 `mh_` 前缀 Key，可随时增删
- **Web 管理后台** — Session 登录，可视化管理邮件 / 域名 / API Key / 管理员
- **Hook Secret** — Worker ↔ PHP 回调签名校验，防伪造邮件注入
- **一键初始化** — `init.sql` 建库建表，admin.php 首次安装引导

## 🏗️ 架构

```
  任意邮箱发送到 user@example.com
         │
         ▼
┌─────────────────────────┐
│  Cloudflare Email Route │
└──────────┬──────────────┘
           │ 转发
           ▼
┌─────────────────────────┐
│  CF Worker (email.js)   │  ← 读取 raw MIME + headers
└──────────┬──────────────┘
           │ POST /mail-hook.php
           │ Header: X-Hook-Token
           ▼
┌─────────────────────────┐
│    PHP + MySQL          │  ← 入库 / API 查询 / Resend 发信
└─────────────────────────┘
           │
           ▼
      Resend API  (按域名轮转)
```

## 📁 目录结构

```
MailHook/
├── admin.php          # 管理后台（Session 登录）
├── api.php            # 外部 API（API Key 鉴权）
├── mail-hook.php      # Worker 回调入口（Hook Secret 鉴权）
├── db.php             # PDO 连接 + 公共函数
├── config.php         # 数据库 / Session 配置（需自行修改）
├── init.sql           # 一键建表脚本
├── API.md             # 完整 API 文档
└── cf worker/
    └── worker.js      # Cloudflare Worker 源码
```

##  快速开始

### 环境要求

| 组件 | 版本 |
|------|------|
| PHP | ≥ 8.0 |
| MySQL | ≥ 5.7（或 MariaDB ≥ 10.3） |
| PHP 扩展 | `pdo_mysql`、`curl`、`mbstring`、`iconv` |
| Cloudflare | 开通 Email Routing 并绑定域名 |

### 1. 建库建表

```bash
mysql -h 127.0.0.1 -u your_user -p your_db < init.sql
```

### 2. 修改配置

编辑 `config.php`：

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME',   'your_db');
define('DB_USER',   'your_user');
define('DB_PASS',   'your_password');
define('SESSION_NAME', 'mailhook_admin');
```

### 3. 部署 PHP 端

把整个目录上传到 PHP 站点根目录（Nginx/Apache 均可），确保：
- `config.php`、`db.php`、`mail-hook.php` **不在 Web 根目录暴露**（可选，源码已有 `IN_MAILHOOK` 防护）
- `logs/` 目录可写（Worker 回调日志）

### 4. 部署 Cloudflare Worker

1. 在 Cloudflare Dashboard → Workers & Pages 创建 Worker
2. 把 `cf worker/worker.js` 的内容粘贴进去
3. 设置两个 Secret：
   ```
   wrangler secret put MAILHOOK_URL   # https://你的域名/mail-hook.php
   wrangler secret put HOOK_SECRET   # 先随便填, 稍后 admin.php 首次安装后会生成正确的
   ```
4. 绑定 **Email Routing** — Routes → 把目标域名路由到这个 Worker

### 5. 首次安装

浏览器打开 `https://你的域名/admin.php`，会看到 **首次安装** 界面：

1. 创建第一个管理员账号
2. 系统自动生成随机 `HOOK_SECRET`
3. 把新 Secret 同步到 Cloudflare Worker：
   ```bash
   wrangler secret put HOOK_SECRET   # 粘贴 admin.php 显示的那串
   ```

## 🔌 API 速览

所有外部请求必须携带 `Api-Key` 或 `Authorization: Bearer` 头。

```
# 查最新邮件（自动提取验证码）
GET /api.php?action=get_mail&prefix=abc&domain=@example.com

# 邮件列表
GET /api.php?action=list_mails&limit=50&prefix=abc

# 发送邮件（Resend 自动轮转）
POST /api.php?action=send_mail
Content-Type: application/json
{
  "to": "target@example.com",
  "subject": "验证码",
  "html": "<p>您的验证码是 123456</p>"
}

# 统计 / 域名列表 / 发送日志 ...
```

完整接口说明见 [API.md](API.md)。

## 🛡️ 安全建议

- `config.php` 数据库凭据不要提交到 Git（可改为环境变量）
- `apikey.txt` 这类临时文件**不要**上传到公开仓库
- 生产环境强制 HTTPS
- 定期在后台 **⚙️ 设置** 页面重新生成 `HOOK_SECRET`
- 所有 API Key 走 `mh_` 前缀但不要硬编码在第三方脚本里

## 🗃️ 数据库

`init.sql` 包含 6 张表：

| 表 | 用途 |
|----|------|
| `admins` | 管理员账号（bcrypt 密码） |
| `api_keys` | 外部访问凭证 |
| `domains` | 邮件域名 + Resend 配置 |
| `mails` | 接收的邮件（含 raw MIME） |
| `sent_logs` | Resend 发信日志 |
| `settings` | 单行全局配置（`hook_secret`） |

## 📄 License

[![Creative Commons License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-blue.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

本作品采用 **署名-非商业性使用-相同方式共享 4.0 国际许可协议**（CC BY-NC-SA 4.0）进行许可。

- ✅ 允许：分享、改编
- 📌 要求：**署名**、**非商业性使用**、**相同方式共享**

详见 [LICENSE.txt](LICENSE.txt)。

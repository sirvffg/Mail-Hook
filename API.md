# Mail Hook API 文档

> 邮件接收与查询系统 API —— 基于 Cloudflare Email Routing + Worker + PHP MySQL

**Base URL**: `https://XXX.XXX`

---

## 鉴权

所有请求必须携带 API Key，二选一：

```
Header: Api-Key: mh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Header: Authorization: Bearer mh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Key 在管理后台 **🔑 API Keys** tab 创建，默认前缀 `mh_` + 48 位 hex。

---

## 响应格式

统一 JSON：

```json
{
  "code": 0,
  "message": "ok",
  "data": {}
}
```

| code | 含义 |
|------|------|
| `0` | 成功 |
| `400` | 参数错误 |
| `401` | API Key 缺失 |
| `403` | API Key 无效 |
| `404` | 数据不存在 |
| `500` | 服务器内部错误 |

---

## 接口列表

### 1. 查最新邮件（注册脚本最常用）

按邮箱前缀查最新一封邮件，自动提取 4-6 位验证码。

```
GET /api.php?action=get_mail&prefix=abc
GET /api.php?action=get_mail&recipient=abc@example.net
```

**Query 参数**（三选一，优先级：recipient > prefix > domain）：

| 参数 | 必填 | 说明 |
|------|------|------|
| `recipient` | 否 | 完整邮箱地址，精确匹配 |
| `prefix` | 否 | 邮箱前缀，如 `abc`，会匹配 `abc@*` |
| `domain` | 否 | 配合 prefix，限定域名，默认 `%`（匹配所有） |

**示例**

```bash
# 查 abc@example.net 最新邮件
curl -H "Api-Key: mh_xxx" \
  "https://api.example.com/api.php?action=get_mail&recipient=abc@example.net"

# 用 prefix 查
curl -H "Api-Key: mh_xxx" \
  "https://api.example.com/api.php?action=get_mail&prefix=abc&domain=@example.net"
```

**响应**

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 123,
    "recipient": "abc@example.net",
    "sender": "no-reply@example.org",
    "subject": "您的验证码是 123456",
    "body": "您的验证码是 123456，10 分钟内有效。",
    "html": "<p>您的验证码是 <b>123456</b>...</p>",
    "raw": "(完整 MIME 原文)",
    "received_at": 1789099600,
    "received_date": "2026-09-11 12:06:40",
    "code": "123456"
  }
}
```

`code` 字段是自动从 body+subject 里提取的 4-6 位数字验证码，注册脚本直接用。

---

### 2. 邮件列表

```
GET /api.php?action=list_mails&limit=50&prefix=abc
```

| 参数 | 必填 | 默认 | 说明 |
|------|------|------|------|
| `limit` | 否 | 50 | 1-100，最多返回 100 条 |
| `prefix` | 否 | — | 按收件人前缀过滤 |

**响应**

```json
{
  "code": 0,
  "data": {
    "count": 50,
    "data": [
      {
        "id": 123,
        "recipient": "abc@example.net",
        "sender": "no-reply@example.org",
        "subject": "您的验证码是 123456",
        "received_at": 1789099600,
        "received_date": "2026-09-11 12:06:40"
      }
    ]
  }
}
```

---

### 3. 邮件详情

```
GET /api.php?action=mail_detail&id=123
```

| 参数 | 必填 | 说明 |
|------|------|------|
| `id` | 是 | 邮件 ID |

**响应**

同 get_mail 的 data 字段，但无自动提取的 `code`。

---

### 4. 删除邮件

```
POST /api.php?action=delete_mail&id=123
```

| 参数 | 必填 | 说明 |
|------|------|------|
| `id` | 是 | 邮件 ID |

**响应**

```json
{"code": 0, "message": "ok"}
```

---

### 5. 统计概览

```
GET /api.php?action=stats
```

无参数。

**响应**

```json
{
  "code": 0,
  "data": {
    "total_mails": 1024,
    "today_mails": 15,
    "admins": 2,
    "domains": 3,
    "api_keys": 3
  }
}
```

---

### 6. 列出可用邮件域名

返回管理员配置的所有可用邮件域名，注册脚本/前端页面先调这个接口，让用户从中挑选。

```
GET /api.php?action=domains
```

无参数。

**响应**

```json
{
  "code": 0,
  "count": 2,
  "data": [
    {
      "id": 1,
      "domain": "example.net",
      "note": "主力域名"
    },
    {
      "id": 2,
      "domain": "example.com",
      "note": "备用"
    }
  ]
}
```

注册邮箱拼接方式: `<前缀>@<domain>`，前端展示建议把所有域名列出来让用户选一个。

---

### 7. 发送邮件（Resend 自动轮转）

通过 Resend 发送邮件，系统自动挑一个配置了 API Key 且今日额度未满的域名。全部用完返回 429。

```
POST /api.php?action=send_mail
Content-Type: application/x-www-form-urlencoded  (或 application/json)
```

**Body 参数**

| 参数 | 必填 | 说明 |
|------|------|------|
| `to` | ✅ | 收件人邮箱 |
| `subject` | ✅ | 主题 |
| `html` | 二选一 | HTML 正文 |
| `text` | 二选一 | 纯文本正文 |
| `from_email` | 否 | 发件人，不传自动用 `no-reply@<选中域名>` |

**成功响应 (200)**

```json
{
  "code": 0,
  "message": "sent ok",
  "resend_id": "b8a4d6c8-1234-5678-abcd-1234567890ab",
  "domain": "example.net",
  "remaining": 99
}
```

| 字段 | 说明 |
|------|------|
| `resend_id` | Resend 返回的邮件 ID |
| `domain` | 实际用了哪个域名的 Resend Key |
| `remaining` | 该域名今日还剩多少额度 |

**错误响应**

| HTTP | code | 场景 |
|------|------|------|
| 400 | 400 | 缺少 to/subject 或 body，to 格式不对 |
| 429 | 429 | 所有域名都没配置 Resend Key 或今日额度用完 |
| 502 | 502 | Resend 返回错误（API Key 无效、超配额、发件域名未验证等） |

**curl 示例**

```bash
curl -X POST "https://api.example.com/api.php?action=send_mail" \
  -H "Api-Key: mh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "target@example.com",
    "subject": "测试邮件",
    "html": "<p>Hello, <b>world</b>!</p>"
  }'
```

> **域名轮转逻辑**：所有域名配置了 Resend API Key 后，系统按 id 顺序挑选今日额度未满的那个。选中后自动 +1。每个域名每日额度在后台 **🌐 域名** tab 里可单独设置（Resend 免费版 100 封）。

---

### 8. 发送日志列表

查询最近的发送记录，配合 `send_mail` 做状态追踪或排查失败原因。

```
GET /api.php?action=sent_logs&limit=50
```

| 参数 | 必填 | 默认 | 说明 |
|------|------|------|------|
| `limit` | 否 | 50 | 1-100 |

**响应**

```json
{
  "code": 0,
  "count": 2,
  "data": [
    {
      "id": 101,
      "domain_id": 1,
      "domain": "example.net",
      "from_email": "no-reply@example.net",
      "to_email": "target@example.com",
      "subject": "测试邮件",
      "resend_id": "b8a4d6c8-...",
      "status": 1,
      "error_msg": null,
      "sent_at": 1789120000,
      "sent_date": "2026-09-12 08:26:40"
    }
  ]
}
```

| status | 含义 |
|--------|------|
| 0 | 待发送 |
| 1 | ✅ 成功 |
| 2 | ❌ 失败（`error_msg` 里有 Resend 返回的错误） |

---

## Worker 回调接口（Cloudflare 内部用）

Worker 把邮件 POST 到 `mail-hook.php`，不在 api.php 路由里。

**URL**: `POST /mail-hook.php`
**鉴权**: `Header: X-Hook-Token: <settings.hook_secret>`

**请求体**（JSON）：

```json
{
  "recipient": "abc@example.net",
  "sender": "no-reply@example.org",
  "subject": "您的验证码",
  "body": "您的验证码是 123456",
  "html": "<p>您的验证码是 <b>123456</b></p>",
  "raw": "(完整 MIME 原文)"
}
```

**响应**

```json
{"ok": true, "id": 123, "mail": "abc@example.net", "body_len": 30, "html_len": 45, "raw_len": 11590}
```

Worker 不做 body/html 解析，用 `postal-mime` 包在 Cloudflare Worker 侧完成，PHP 直接入库。

---

## 管理后台 Session 接口（admin.php 前端用）

这些接口走 **PHP Session** 鉴权，不用 Api-Key，仅供后台页面调用。

### 登录

```
POST /api.php?action=login
Content-Type: application/x-www-form-urlencoded

username=admin&password=admin123
```

成功后 Set-Cookie: `PHPSESSID=xxx`，后续请求自动携带。

**响应**

```json
{"code": 0, "data": {"username": "admin", "id": 1}}
```

### 登出

```
GET /api.php?action=logout
```

### 当前用户

```
GET /api.php?action=me
```

### 管理员列表

```
GET /api.php?action=users
```

### 新建管理员

```
POST /api.php?action=add_user
Content-Type: application/x-www-form-urlencoded

username=newuser&password=pass123
```

### 修改密码

```
POST /api.php?action=change_password
Content-Type: application/x-www-form-urlencoded

old_password=xxx&new_password=yyy
```

### 删除管理员

```
POST /api.php?action=delete_user&id=3
```

### 系统设置（HOOK_SECRET / TOKEN_TTL）

```
GET  /api.php?action=get_settings
POST /api.php?action=update_setting&key=HOOK_SECRET&value=xxx
POST /api.php?action=regenerate_hook_secret
POST /api.php?action=flush_tokens
```

### 管理员 API Key 管理

```
GET  /api.php?action=list_api_keys
POST /api.php?action=create_api_key&name=my_register_script
POST /api.php?action=delete_api_key&id=5
```

---

## Python 注册脚本示例

```python
import requests, time, random

BASE = "https://api.example.com"
HEADERS = {"Api-Key": "mh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"}

def get_available_domains() -> list[str]:
    """拉取管理员配置的所有可用邮件域名"""
    r = requests.get(f"{BASE}/api.php", params={"action": "domains"}, headers=HEADERS, timeout=5)
    data = r.json()
    if data["code"] != 0:
        raise RuntimeError(f"拉域名失败: {data}")
    return [d["domain"] for d in data["data"]]

def wait_for_code(prefix: str, domain: str, timeout: int = 60) -> str:
    """轮询直到邮件到达, 返回验证码"""
    recipient = f"{prefix}@{domain}"
    deadline = time.time() + timeout
    while time.time() < deadline:
        r = requests.get(f"{BASE}/api.php", params={
            "action": "get_mail",
            "recipient": recipient,
        }, headers=HEADERS, timeout=5)
        if r.status_code == 200:
            data = r.json()["data"]
            if "code" in data and data["code"]:
                return data["code"]
        time.sleep(2)
    raise TimeoutError("邮件未在超时内到达")

# 使用: 先拿域名列表 → 随机选一个 → 等验证码
domains = get_available_domains()
domain  = random.choice(domains)
prefix  = "abc123"

print(f"注册邮箱: {prefix}@{domain}")
code = wait_for_code(prefix, domain)
print(f"验证码: {code}")
```

---

## 错误码速查

| HTTP | code | 场景 |
|------|------|------|
| 200 | 0 | 成功 |
| 400 | 400 | 缺少必填参数（如没传 prefix/recipient） |
| 401 | 401 | 没带 Api-Key / Session 过期 |
| 403 | 403 | Api-Key 无效 / HOOK_SECRET 不匹配 |
| 404 | 404 | 该邮箱还没收到过邮件 / ID 不存在 |
| 500 | 500 | MySQL 断连、表缺失等服务端异常 |
| 520 | — | Worker 调 mail-hook.php 返回非 2xx（PHP 报错会被 CF 转 520） |
| 522 | — | Worker 到 PHP 超时（默认 15s，body 太大可能触发） |

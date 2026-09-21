// Cloudflare Worker — 纯转发版，不做任何 MIME 解析
// 鉴权：X-Hook-Token 请求头
export default {
  async email(message, env, ctx) {
    // 1. 收件人
    const tos = Array.isArray(message.to) ? message.to : [message.to];
    const recipient = tos.map(t => t?.address || t || '').filter(Boolean).join(',');

    // 2. 收集所有能拿到的字段，不做任何加工
    const info = {
      recipient,
      from: message.from || '',
      to: message.to || '',
      headers: {},
      rawLength: null,
      rawText: '',
      rawError: null,
    };

    // 3. 把所有 headers dump 出来
    try {
      if (message.headers && typeof message.headers.forEach === 'function') {
        message.headers.forEach((v, k) => { info.headers[k] = v; });
      } else if (message.headers) {
        for (const [k, v] of message.headers) info.headers[k] = v;
      }
    } catch (e) {
      info.headersError = String(e);
    }

    // 4. 读 raw —— message.raw 是属性（ReadableStream），不是函数
    try {
      const rawResp = new Response(message.raw);
      const buf = await rawResp.arrayBuffer();
      info.rawLength = buf.byteLength;
      info.rawText = new TextDecoder().decode(buf);
    } catch (e) {
      info.rawError = String(e) + ' | ' + (e?.stack || '');
    }

    // 5. 打印到 Workers 日志
    console.log('[debug] recipient =', recipient);
    console.log('[debug] from      =', message.from);
    console.log('[debug] rawLength =', info.rawLength);
    console.log('[debug] rawError  =', info.rawError);

    // 6. 原样转发给 PHP
    if (!env.MAILHOOK_URL) {
      console.error('[debug] MAILHOOK_URL not set');
      return;
    }

    ctx.waitUntil(
      fetch(env.MAILHOOK_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Hook-Token': env.HOOK_SECRET || '',
        },
        body: JSON.stringify({
          recipient,
          sender:  message.from || '',
          subject: info.headers['subject'] || '',
          raw:     info.rawText,
          debug:   info,
        }),
      })
        .then(async r => {
          const t = await r.text().catch(() => '');
          console.log(`[debug] ${r.status} resp=${t.slice(0, 300)}`);
        })
        .catch(e => console.error('[debug] FAIL', e)),
    );
  },
};
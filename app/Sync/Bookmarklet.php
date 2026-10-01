<?php

namespace App\Sync;

/**
 * The «مزامنة المشتركين» bookmark. Clicked on admin.ftth.iq, it reads the subscriber lists with
 * the user's own session (from inside Iraq, where the panel is reachable), and hands them to this
 * system through a small window. It only sends GET requests to the panel.
 */
class Bookmarklet
{
    public static function href(string $appUrl, string $clientApp): string
    {
        $js = strtr(self::SCRIPT, [
            '__APP__' => json_encode(rtrim($appUrl, '/'), JSON_UNESCAPED_SLASHES),
            '__CLIENT_APP__' => json_encode($clientApp),
            '__PANEL_HOST__' => json_encode(parse_url(\App\Http\Controllers\BrowserSyncController::PANEL_ORIGIN, PHP_URL_HOST)),
        ]);

        return 'javascript:'.rawurlencode($js);
    }

    private const SCRIPT = <<<'JS'
(async () => {
  const APP = __APP__, PANEL_HOST = __PANEL_HOST__;
  if (location.host !== PANEL_HOST) { alert('افتح صفحة موقع الشركة ' + PANEL_HOST + ' وسجّل الدخول، ثم اضغط الزر.'); return; }
  const token = localStorage.getItem('access_token');
  if (!token) { alert('سجّل الدخول لموقع الشركة أولاً، ثم اضغط الزر.'); return; }
  const win = window.open(APP + '/sync/browser', 'subs_sync', 'width=520,height=600');
  if (!win) { alert('اسمح بالنوافذ المنبثقة لهذا الموقع ثم اضغط الزر مرة ثانية.'); return; }

  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;z-index:2147483647;top:14px;left:14px;max-width:360px;background:#0f766e;color:#fff;padding:12px 16px;border-radius:12px;font:14px/1.6 system-ui,Tahoma,sans-serif;direction:rtl;box-shadow:0 6px 20px rgba(0,0,0,.35)';
  document.body.appendChild(box);
  const say = (t) => { box.textContent = 'مزامنة المشتركين: ' + t; };

  const ready = new Promise((resolve) => {
    const f = (e) => { if (e.origin === APP && e.data && e.data.type === 'ready') { removeEventListener('message', f); resolve(); } };
    addEventListener('message', f);
  });
  const send = (data) => new Promise((resolve, reject) => {
    const f = (e) => {
      if (e.origin !== APP || !e.data) return;
      if (e.data.type === data.type + '_ok') { removeEventListener('message', f); resolve(e.data); }
      if (e.data.type === 'error') { removeEventListener('message', f); reject(new Error(e.data.message)); }
    };
    addEventListener('message', f);
    win.postMessage(data, APP);
  });

  const headers = { Authorization: 'Bearer ' + token, 'X-Client-App': __CLIENT_APP__, 'X-User-Role': '0', Accept: 'application/json' };
  const get = async (path) => {
    const r = await fetch(path, { headers, credentials: 'include' });
    if (r.status === 401) throw new Error('انتهت جلسة موقع الشركة. حدّث الصفحة وسجّل الدخول ثم أعد المحاولة.');
    if (!r.ok) throw new Error(path + ' (' + r.status + ')');
    return r.json();
  };
  const all = async (path, label) => {
    let items = [], page = 1, total = 0;
    do {
      const body = await get(path + (path.includes('?') ? '&' : '?') + 'pageSize=100&pageNumber=' + page);
      const got = Array.isArray(body.items) ? body.items : [];
      items = items.concat(got); total = body.totalCount || 0; page++;
      say(label + ' ' + items.length + ' من ' + total);
      if (!got.length) break;
    } while (items.length < total && page < 1000);
    return items;
  };
  const pick = (o, keys) => { const r = {}; for (const k of keys) if (o && o[k] !== undefined) r[k] = o[k]; return r; };

  try {
    const customers = (await all('/api/customers', 'جلب المشتركين')).map((c) => pick(c, ['id', 'self', 'displayValue', 'name', 'primaryPhone']));
    let subscriptions = null, lastError = null;
    for (const q of ['', '?hierarchyLevel=0', '?hierarchyLevel=1', '?hierarchyLevel=2']) {
      try { subscriptions = await all('/api/subscriptions' + q, 'جلب الاشتراكات'); break; } catch (e) { lastError = e; }
    }
    if (!subscriptions) throw lastError;
    subscriptions = subscriptions.map((s) => pick(s, ['id', 'self', 'username', 'status', 'services', 'bundle', 'bundleId', 'zone', 'expires', 'expiresAt', 'customer', 'deviceDetails']));

    say('إرسال ' + subscriptions.length + ' اشتراك للنظام…');
    await ready;
    const plan = await send({ type: 'plan', customers, subscriptions });

    const ids = plan.needs || [], details = {};
    let next = 0, done = 0;
    const worker = async () => {
      while (next < ids.length) {
        const id = ids[next++];
        try {
          const c = await get('/api/customers/' + encodeURIComponent(id));
          const s = await get('/api/customers/subscriptions?customerId=' + encodeURIComponent(id));
          details[id] = { customer: c.model || {}, subscriptions: s.items || [] };
        } catch (e) { /* skipped: the next sync will ask again */ }
        say('جلب التفاصيل ' + (++done) + ' من ' + ids.length);
      }
    };
    await Promise.all([worker(), worker(), worker(), worker()]);

    say('حفظ في النظام…');
    const result = await send({ type: 'run', customers, subscriptions, details });
    say('تمت ✓ ' + result.summary);
    box.style.background = '#15803d';
    setTimeout(() => box.remove(), 20000);
  } catch (e) {
    say('فشلت: ' + e.message);
    box.style.background = '#b91c1c';
  }
})();
JS;
}

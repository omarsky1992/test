<?php

namespace App\Sync;

use App\Http\Controllers\BrowserSyncController;

/**
 * The JavaScript that reads the company panel in the user's browser (where, unlike the server,
 * it is reachable from Iraq), in two wrappers: a bookmark, and a small Chrome extension that
 * starts by itself on a tab opened by the «مزامنة الآن» button, after the user signs in.
 * Both only send GET requests to the panel and hand the data to a window of this system.
 */
class BrowserScripts
{
    public static function bookmarkletHref(string $appUrl, string $clientApp): string
    {
        return 'javascript:'.rawurlencode(self::fill(self::CORE.self::BOOKMARKLET, $appUrl, $clientApp));
    }

    public static function contentScript(string $appUrl, string $clientApp): string
    {
        return self::fill(self::CORE.self::EXTENSION, $appUrl, $clientApp);
    }

    public static function manifest(string $appUrl): string
    {
        return json_encode([
            'manifest_version' => 3,
            'name' => 'مزامنة المشتركين – '.parse_url($appUrl, PHP_URL_HOST),
            'version' => '1.0.0',
            'description' => 'يقرأ قائمة الاشتراكات من موقع الشركة بجلستك ويرسلها لنظام المشتركين. قراءة فقط.',
            'content_scripts' => [[
                'matches' => [BrowserSyncController::PANEL_ORIGIN.'/*'],
                'js' => ['content.js'],
                'run_at' => 'document_idle',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function fill(string $js, string $appUrl, string $clientApp): string
    {
        return strtr($js, [
            '__APP__' => json_encode(rtrim($appUrl, '/'), JSON_UNESCAPED_SLASHES),
            '__CLIENT_APP__' => json_encode($clientApp),
            '__PANEL_HOST__' => json_encode(parse_url(BrowserSyncController::PANEL_ORIGIN, PHP_URL_HOST)),
        ]);
    }

    /** Reads the lists, asks the system which details it needs, reads those, and sends everything. */
    private const CORE = <<<'JS'
const SUBS_APP = __APP__, SUBS_PANEL_HOST = __PANEL_HOST__, SUBS_CLIENT_APP = __CLIENT_APP__;
const subsBox = () => {
  let box = document.getElementById('subs-sync-box');
  if (!box) {
    box = document.createElement('div');
    box.id = 'subs-sync-box';
    box.style.cssText = 'position:fixed;z-index:2147483647;top:14px;left:14px;max-width:380px;background:#0f766e;color:#fff;padding:12px 16px;border-radius:12px;font:14px/1.7 system-ui,Tahoma,sans-serif;direction:rtl;box-shadow:0 6px 20px rgba(0,0,0,.35)';
    document.body.appendChild(box);
  }
  return box;
};
const subsToken = () => {
  const t = localStorage.getItem('access_token');
  if (!t) return null;
  try {
    const exp = JSON.parse(atob(t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))).exp;
    return exp && exp * 1000 > Date.now() + 30000 ? t : null;
  } catch (e) { return null; }
};
async function subsSync(target, ready) {
  const box = subsBox();
  const say = (t) => { box.textContent = 'مزامنة المشتركين: ' + t; try { target.postMessage({ type: 'progress', text: t }, SUBS_APP); } catch (e) {} };
  const send = (data) => new Promise((resolve, reject) => {
    const f = (e) => {
      if (e.origin !== SUBS_APP || !e.data) return;
      if (e.data.type === data.type + '_ok') { removeEventListener('message', f); resolve(e.data); }
      if (e.data.type === 'error') { removeEventListener('message', f); reject(new Error(e.data.message)); }
    };
    addEventListener('message', f);
    target.postMessage(data, SUBS_APP);
  });
  const token = subsToken();
  if (!token) throw new Error('سجّل الدخول لموقع الشركة أولاً.');
  const headers = { Authorization: 'Bearer ' + token, 'X-Client-App': SUBS_CLIENT_APP, 'X-User-Role': '0', Accept: 'application/json' };
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

  const customers = (await all('/api/customers', 'جلب المشتركين')).map((c) => pick(c, ['id', 'self', 'displayValue', 'name', 'primaryPhone']));
  // Reuse the exact query the subscriptions page (admin.ftth.iq/subscriptions) sent for its own list.
  const queries = [];
  const seen = performance.getEntriesByType('resource').map((e) => e.name).filter((n) => /\/api\/subscriptions\?/.test(n)).pop();
  if (seen) {
    const params = new URL(seen).searchParams;
    params.delete('pageSize'); params.delete('pageNumber');
    queries.push('?' + params.toString());
  }
  queries.push('', '?hierarchyLevel=0', '?hierarchyLevel=1', '?hierarchyLevel=2');
  let subscriptions = null, lastError = null;
  for (const q of queries) {
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
  box.textContent = 'مزامنة المشتركين: تمت ✓ ' + result.summary;
  box.style.background = '#15803d';
  return result;
}
const subsWaitReady = (win) => new Promise((resolve) => {
  const f = (e) => { if (e.origin === SUBS_APP && e.data && e.data.type === 'ready') { removeEventListener('message', f); resolve(); } };
  addEventListener('message', f);
});

JS;

    private const BOOKMARKLET = <<<'JS'
(async () => {
  if (location.host !== SUBS_PANEL_HOST) { alert('افتح صفحة موقع الشركة ' + SUBS_PANEL_HOST + ' وسجّل الدخول، ثم اضغط الزر.'); return; }
  if (!subsToken()) { alert('سجّل الدخول لموقع الشركة أولاً، ثم اضغط الزر.'); return; }
  const win = window.open(SUBS_APP + '/sync/browser', 'subs_sync', 'width=520,height=600');
  if (!win) { alert('اسمح بالنوافذ المنبثقة لهذا الموقع ثم اضغط الزر مرة ثانية.'); return; }
  const ready = subsWaitReady(win);
  try { await subsSync(win, ready); } catch (e) { const b = subsBox(); b.textContent = 'مزامنة المشتركين: فشلت: ' + e.message; b.style.background = '#b91c1c'; }
})();
JS;

    /**
     * Runs on every admin.ftth.iq page but acts only on a tab opened by the system's «مزامنة الآن»
     * button (marked #subs-sync). It waits for the sign-in, then syncs into the system tab that
     * opened it. If the sign-in pages cut that link, one click opens a small system window instead.
     */
    private const EXTENSION = <<<'JS'
(() => {
  const KEY = 'subs_sync_pending';
  if (location.hash.includes('subs-sync')) {
    sessionStorage.setItem(KEY, String(Date.now()));
    history.replaceState(null, '', location.pathname + location.search);
  }
  const since = Number(sessionStorage.getItem(KEY) || 0);
  if (!since || Date.now() - since > 15 * 60 * 1000) { sessionStorage.removeItem(KEY); return; }

  const opener = window.opener;
  const tell = (msg) => { try { if (opener && !opener.closed) opener.postMessage(msg, SUBS_APP); } catch (e) {} };
  tell({ type: 'hello' });
  const box = subsBox();
  box.textContent = 'مزامنة المشتركين: سجّل الدخول لموقع الشركة، وتبدأ المزامنة تلقائياً بعده.';

  const fail = (e) => { box.textContent = 'مزامنة المشتركين: فشلت: ' + e.message; box.style.background = '#b91c1c'; tell({ type: 'progress', text: 'فشلت: ' + e.message }); };
  const start = () => {
    sessionStorage.removeItem(KEY);
    if (opener && !opener.closed) {
      subsSync(opener, Promise.resolve()).catch(fail);
      return;
    }
    box.innerHTML = '';
    box.append('مزامنة المشتركين: تم تسجيل الدخول. ');
    const button = document.createElement('button');
    button.textContent = 'اضغط هنا لإكمال المزامنة';
    button.style.cssText = 'margin-top:6px;padding:6px 12px;border:0;border-radius:8px;background:#fff;color:#0f766e;font:inherit;font-weight:700;cursor:pointer';
    button.onclick = () => {
      const win = window.open(SUBS_APP + '/sync/browser', 'subs_sync', 'width=520,height=600');
      if (!win) { alert('اسمح بالنوافذ المنبثقة لهذا الموقع ثم اضغط مرة ثانية.'); return; }
      subsSync(win, subsWaitReady(win)).catch(fail);
    };
    box.append(button);
  };

  const wait = setInterval(() => {
    if (subsToken() && document.readyState === 'complete') { clearInterval(wait); setTimeout(start, 1500); }
  }, 1000);
})();
JS;
}

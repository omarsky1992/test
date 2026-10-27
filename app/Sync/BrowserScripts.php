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
            'version' => '1.3.2',
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
// The wallet log is /api/transactions itself (not /api/transactions/payment-methods, which the subscriber pages call).
const SUBS_WALLET_API = /\/api\/transactions(?:\?|$)/i;
const SUBS_WALLET_LOG = '/api/transactions?sortCriteria.property=occuredAt&sortCriteria.direction=desc';
// Newest lines first, without the page's own page size and number.
const subsWalletPath = (u) => {
  const x = new URL(u, location.origin);
  x.searchParams.delete('pageSize'); x.searchParams.delete('pageNumber');
  x.searchParams.set('sortCriteria.property', 'occuredAt'); x.searchParams.set('sortCriteria.direction', 'desc');
  return x.pathname + x.search;
};
// What admin.ftth.iq's device window («معلومات تقنية») calls: /api/subscriptions/device/ont?username=…
const SUBS_ONT_PATH = '/api/subscriptions/device/ont';
const SUBS_ONT_API = /\/ont\?username=/i;
// Remembers the address a panel page itself used to read a device's ONT («القدرة المستلمة»).
const subsLearnOnt = () => {
  const seen = performance.getEntriesByType('resource').map((e) => e.name).filter((n) => SUBS_ONT_API.test(n)).pop();
  if (!seen) return null;
  const path = new URL(seen).pathname;
  try { localStorage.setItem('subs_ont_api', path); } catch (e) {}
  return path;
};
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
// What both runs share: the progress box, messages to the system window, and GET requests with the panel's current token.
const subsConnect = (target, title) => {
  const box = subsBox();
  const say = (t) => { box.textContent = title + ': ' + t; try { target.postMessage({ type: 'progress', text: t }, SUBS_APP); } catch (e) {} };
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
  const headers = { 'X-Client-App': SUBS_CLIENT_APP, 'X-User-Role': '0', Accept: 'application/json' };
  const get = async (path) => {
    // The panel renews its sign-in token while it is open; a long run must use the current one.
    const r = await fetch(path, { headers: { ...headers, Authorization: 'Bearer ' + (subsToken() || token) }, credentials: 'include' });
    if (r.status === 401) throw new Error('انتهت جلسة موقع الشركة. حدّث الصفحة وسجّل الدخول ثم أعد المحاولة.');
    if (!r.ok) throw new Error(path + ' (' + r.status + ')');
    return r.json();
  };
  return { box, say, send, get };
};
async function subsSync(target, ready) {
  const { box, say, send, get } = subsConnect(target, 'مزامنة المشتركين');
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

  // The wallet log (admin.ftth.iq/wallet/transactions) says whether each renewal was paid by Card or Wallet.
  // Its address is the one the wallet page itself used (remembered when that page was opened), else a known guess.
  let wallet = null;
  try {
    const paths = [SUBS_WALLET_LOG];
    const seenWallet = performance.getEntriesByType('resource').map((e) => e.name).filter((n) => SUBS_WALLET_API.test(n)).pop();
    if (seenWallet) paths.push(subsWalletPath(seenWallet));
    // An address remembered by an older version may be another list (payment-methods): only the log is kept.
    try { const learned = localStorage.getItem('subs_wallet_api'); if (learned && SUBS_WALLET_API.test(learned)) paths.push(subsWalletPath(learned)); } catch (e) {}
    paths.push('/api/wallet/transactions', '/api/wallets/transactions');
    for (const p of [...new Set(paths)]) {
      try {
        let items = [];
        for (let page = 1; page <= 3; page++) {
          const body = await get(p + (p.includes('?') ? '&' : '?') + 'pageSize=100&pageNumber=' + page);
          const got = Array.isArray(body.items) ? body.items : (Array.isArray(body) ? body : null);
          if (got === null) throw new Error('not a list');
          items = items.concat(got);
          say('جلب حركات المحفظة ' + items.length);
          if (got.length < 100) break;
        }
        wallet = { path: p, items };
        try { localStorage.setItem('subs_wallet_api', p); } catch (e) {}
        break;
      } catch (e) { /* try the next address */ }
    }
  } catch (e) { wallet = null; }

  say('حفظ في النظام…');
  const result = await send({ type: 'run', customers, subscriptions, details, wallet });
  box.textContent = 'مزامنة المشتركين: تمت ✓ ' + result.summary;
  box.style.background = '#15803d';
  return result;
}
// «القدرة المستلمة» on its own (a separate button, so it never slows the activations sync): the system says
// which devices to read (oldest reading first), each ONT is read through the panel's own address, then saved.
async function subsOntSync(target, ready) {
  const { box, say, send, get } = subsConnect(target, 'القدرة المستلمة');
  await ready;
  // Oldest reading first: after a stop, the next run starts with the devices not read yet.
  const plan = await send({ type: 'ont_plan' });
  const names = plan.ont || [];
  if (!names.length) {
    const text = plan.message || 'لا توجد أجهزة لقراءتها.';
    try { target.postMessage({ type: 'ont_done', text, ok: true }, SUBS_APP); } catch (e) {}
    box.textContent = 'القدرة المستلمة: ' + text;
    box.style.background = '#15803d';
    return { summary: text };
  }
  // Only the number and the status are kept and sent.
  const slim = (body) => { const m = (body && body.model) || body || {}; return { model: { rxPower: m.rxPower ?? null, status: m.status ?? null } }; };
  // A reply with neither a number nor a status is empty (the site was busy): it is not saved.
  const empty = (body) => { const m = slim(body).model; return (m.rxPower === null || m.rxPower === undefined) && !m.status; };
  let emptySample = null;
  // A save the system never answers must not hold the run: give up after 45 seconds.
  const sendWithin = (data, ms) => Promise.race([send(data), new Promise((_, no) => setTimeout(() => no(new Error('النظام لم يرد على الحفظ.')), ms))]);
  // Each reading is saved within seconds, in small batches, one batch at a time, so a stop loses nothing.
  let queue = [], saved = 0, failed = 0, stopped = null, chain = Promise.resolve();
  const flush = () => (chain = chain.then(async () => {
    if (!queue.length) return;
    const batch = Object.fromEntries(queue.splice(0));
    try { saved += (await sendWithin({ type: 'ont_run', ont: batch }, 45000)).count || 0; } catch (e) { stopped = stopped || e.message; }
  }));
  const keep = (name, body) => { queue.push([name, slim(body)]); if (queue.length >= 10) flush(); };
  // Also every few seconds, so slow reading still saves as it goes.
  const ticker = setInterval(() => { if (queue.length) flush(); }, 5000);
  const sessionLost = (e) => /انتهت جلسة/.test(e.message);
  const pause = (ms) => new Promise((r) => setTimeout(r, ms));

  let base = subsLearnOnt();
  try { base = base || localStorage.getItem('subs_ont_api'); } catch (e) {}
  const bases = [...new Set([base, SUBS_ONT_PATH, '/api/subscriptions/ont', '/api/ont'].filter(Boolean))];
  let working = null;
  for (const b of bases) {
    try { const body = await get(b + '?username=' + encodeURIComponent(names[0])); working = b; if (!empty(body)) keep(names[0], body); break; } catch (e) { if (sessionLost(e)) { clearInterval(ticker); throw e; } }
  }
  if (!working) { clearInterval(ticker); throw new Error('لم أجد عنوان القراءة: افتح «معلومات تقنية» لأي مشترك مرة واحدة ثم أعد المحاولة.'); }
  try { localStorage.setItem('subs_ont_api', working); } catch (e) {}

  // Gently: two at a time with a short pause, since each reading asks the fibre equipment live.
  let next = 1, done = 1;
  const read = async (name) => {
    const url = working + '?username=' + encodeURIComponent(name);
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        const body = await get(url);
        if (!empty(body)) return body;
        emptySample = emptySample || JSON.stringify(body).slice(0, 160);
      } catch (e) {
        if (sessionLost(e)) throw e;
        emptySample = emptySample || e.message;
      }
      await pause(2500 * (attempt + 1));
    }
    return null;
  };
  const worker = async () => {
    while (next < names.length && !stopped) {
      const name = names[next++];
      try {
        const body = await read(name);
        if (body) keep(name, body); else failed++;
      } catch (e) {
        if (sessionLost(e)) { stopped = e.message; break; }
        failed++;
      }
      if (++done % 5 === 0 || done === names.length) say('قراءة ' + done + ' من ' + names.length + ' · حُفظ ' + saved + (failed ? ' · تعذّر ' + failed : ''));
      await pause(300);
    }
  };
  await Promise.all([worker(), worker()]);
  clearInterval(ticker);
  flush();
  await chain;

  const left = names.length - saved;
  const text = 'حُفظت القدرة المستلمة لـ ' + saved + ' جهاز' + (failed ? ' · تعذّرت ' + failed + ' (تنقرا بالضغطة الجاية)' + (emptySample ? ' · مثال رد: ' + emptySample : '') : '')
    + (stopped ? ' · توقفت: ' + stopped + ' اضغط الزر مرة ثانية ويكمل من حيث توقف' + (left > 0 ? ' (باقي ' + left + ')' : '') : '');
  try { target.postMessage({ type: 'ont_done', text, ok: !stopped }, SUBS_APP); } catch (e) {}
  box.textContent = 'القدرة المستلمة: ' + (stopped ? '' : 'تمت ✓ ') + text;
  box.style.background = stopped ? '#b45309' : '#15803d';
  return { summary: text };
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
  if (location.hash.includes('subs-sync') || location.hash.includes('subs-ont')) {
    sessionStorage.setItem(KEY, String(Date.now()));
    sessionStorage.setItem(KEY + '_mode', location.hash.includes('subs-ont') ? 'ont' : 'sync');
    history.replaceState(null, '', location.pathname + location.search);
  }
  const run = sessionStorage.getItem(KEY + '_mode') === 'ont' ? subsOntSync : subsSync;
  // On any panel page: when a device window («معلومات تقنية») is opened, remember the ONT address.
  const learnOnt = setInterval(() => { if (subsLearnOnt()) clearInterval(learnOnt); }, 2000);
  setTimeout(() => clearInterval(learnOnt), 10 * 60 * 1000);
  // On the wallet page: remember the address it reads its log from, so the sync reads the same one.
  if (location.pathname.startsWith('/wallet')) {
    const learn = setInterval(() => {
      const seen = performance.getEntriesByType('resource').map((e) => e.name).filter((n) => SUBS_WALLET_API.test(n)).pop();
      if (!seen) return;
      try { localStorage.setItem('subs_wallet_api', subsWalletPath(seen)); } catch (e) {}
      clearInterval(learn);
    }, 1500);
    setTimeout(() => clearInterval(learn), 60000);
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
      run(opener, Promise.resolve()).catch(fail);
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
      run(win, subsWaitReady(win)).catch(fail);
    };
    box.append(button);
  };

  // Start once signed in and the page has requested its own (filtered) list, so the same filter is used.
  let signedInAt = 0;
  const listed = () => performance.getEntriesByType('resource').some((e) => /\/api\/subscriptions\?/.test(e.name));
  const wait = setInterval(() => {
    if (!subsToken() || document.readyState !== 'complete') return;
    signedInAt = signedInAt || Date.now();
    if (listed() || Date.now() - signedInAt > 15000) { clearInterval(wait); setTimeout(start, 800); }
  }, 1000);
})();
JS;
}

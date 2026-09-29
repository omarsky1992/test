/*
 * تصدير جميع المشتركين من admin.ftth.iq مع كامل التفاصيل (CSV + JSON)
 *
 * طريقة الاستخدام:
 *   1. افتح https://admin.ftth.iq وسجّل الدخول كالمعتاد.
 *   2. اضغط F12 ← تبويب Console.
 *   3. الصق هذا الملف كاملاً واضغط Enter (إذا منع Chrome اللصق اكتب: allow pasting).
 *   4. انتظر حتى ينزل ملفان: ftth-customers.csv و ftth-customers.json
 *
 * السكربت يستخدم نفس الـ API الذي تستخدمه لوحة التحكم وبنفس جلسة دخولك،
 * لذلك لا يرى إلا المشتركين المسموح لحسابك برؤيتهم.
 * إذا فشل مسار من المسارات أدناه، افتح تبويب Network وابحث عن الطلب الذي
 * يجلب قائمة المشتركين/التفاصيل/الاشتراكات، ثم عدّل الدوال في CONFIG.
 */
(async () => {
  const CONFIG = {
    apiBase: location.origin + '/api',
    pageSize: 100,          // عدد المشتركين في كل طلب (أسرع من 10)
    delayMs: 250,           // مهلة بين الطلبات حتى لا يتم حظر الحساب
    listUrl: (page, size) =>
      `/customers?pageSize=${size}&pageNumber=${page}` +
      `&sortCriteria.property=self.displayValue&sortCriteria.direction=asc`,
    detailsUrl: (id) => `/customers/${id}`,
    subscriptionsUrl: (id) => `/customers/subscriptions?customerId=${id}`,
  };

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // ---------- 1) إيجاد توكن الدخول (JWT) من ذاكرة المتصفح ----------
  function findJwt(value) {
    if (typeof value !== 'string') return null;
    const m = value.match(/eyJ[\w-]+\.[\w-]+\.[\w-]+/);
    if (m) return m[0];
    try {
      const obj = JSON.parse(value);
      for (const v of Object.values(obj || {})) {
        const t = findJwt(typeof v === 'string' ? v : JSON.stringify(v));
        if (t) return t;
      }
    } catch (_) {}
    return null;
  }
  let token = null;
  for (const store of [localStorage, sessionStorage]) {
    for (let i = 0; i < store.length && !token; i++) {
      token = findJwt(store.getItem(store.key(i)));
    }
  }
  if (!token) {
    const c = document.cookie.split('; ').map((x) => x.split('=').slice(1).join('='));
    token = c.map(decodeURIComponent).map(findJwt).find(Boolean) || null;
  }
  console.log(token ? '✅ تم العثور على توكن الدخول' : '⚠️ لم يُعثر على توكن، سيتم الاعتماد على الكوكيز');

  // ---------- 2) دالة طلب مع إعادة المحاولة ----------
  async function api(path, tries = 3) {
    for (let t = 1; t <= tries; t++) {
      const res = await fetch(CONFIG.apiBase + path, {
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: 'Bearer ' + token } : {}),
        },
      });
      if (res.ok) return res.json();
      if (res.status === 401 || res.status === 403) {
        throw new Error(`غير مصرح (${res.status}) على ${path} — سجّل الدخول من جديد أو عدّل CONFIG`);
      }
      if (res.status === 404) return null;
      await sleep(1000 * t);
    }
    throw new Error('فشل الطلب: ' + path);
  }

  const itemsOf = (r) =>
    Array.isArray(r) ? r : r?.items ?? r?.data?.items ?? r?.data ?? r?.result ?? r?.results ?? [];
  const totalOf = (r) => r?.totalCount ?? r?.total ?? r?.data?.totalCount ?? null;
  const idOf = (c) => c?.self?.id ?? c?.id ?? c?.customerId ?? c?.customer?.id;

  // ---------- 3) جلب قائمة كل المشتركين ----------
  const customers = [];
  for (let page = 1; ; page++) {
    const r = await api(CONFIG.listUrl(page, CONFIG.pageSize));
    const items = itemsOf(r);
    customers.push(...items);
    const total = totalOf(r);
    console.log(`📄 صفحة ${page}: ${customers.length}${total ? ' / ' + total : ''}`);
    if (!items.length || items.length < CONFIG.pageSize || (total && customers.length >= total)) break;
    await sleep(CONFIG.delayMs);
  }
  if (!customers.length) {
    console.error('❌ القائمة فارغة. افتح Network وانسخ رابط طلب قائمة المشتركين وعدّل CONFIG.listUrl');
    return;
  }

  // ---------- 4) جلب التفاصيل والاشتراكات لكل مشترك ----------
  const full = [];
  for (let i = 0; i < customers.length; i++) {
    const c = customers[i];
    const id = idOf(c);
    let details = null, subscriptions = [];
    try { details = await api(CONFIG.detailsUrl(id)); } catch (e) { console.warn(id, e.message); }
    await sleep(CONFIG.delayMs);
    try { subscriptions = itemsOf(await api(CONFIG.subscriptionsUrl(id))); } catch (e) { console.warn(id, e.message); }
    await sleep(CONFIG.delayMs);
    full.push({ list: c, details: details?.model ?? details, subscriptions });
    if ((i + 1) % 10 === 0 || i === customers.length - 1) {
      console.log(`👤 ${i + 1} / ${customers.length}`);
    }
  }

  // ---------- 5) تسطيح البيانات إلى أعمدة ----------
  function flatten(obj, prefix = '', out = {}) {
    if (obj === null || obj === undefined) return out;
    if (Array.isArray(obj)) {
      if (obj.every((x) => typeof x !== 'object' || x === null)) {
        out[prefix] = obj.join(' | ');
      } else {
        obj.forEach((x, i) => flatten(x, `${prefix}[${i}]`, out));
      }
    } else if (typeof obj === 'object') {
      for (const [k, v] of Object.entries(obj)) flatten(v, prefix ? `${prefix}.${k}` : k, out);
    } else {
      out[prefix] = obj;
    }
    return out;
  }
  const rows = full.map((f) =>
    flatten({ ...f.details, subscriptions: f.subscriptions }, '', flatten(f.list, 'list'))
  );
  const columns = [...new Set(rows.flatMap(Object.keys))];

  const esc = (v) => {
    const s = v === undefined || v === null ? '' : String(v);
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = '﻿' + [columns.map(esc).join(','), ...rows.map((r) => columns.map((c) => esc(r[c])).join(','))].join('\r\n');

  // ---------- 6) تنزيل الملفات ----------
  function download(name, content, type) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([content], { type }));
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }
  download('ftth-customers.csv', csv, 'text/csv;charset=utf-8');
  download('ftth-customers.json', JSON.stringify(full, null, 2), 'application/json');
  console.log(`🎉 تم تصدير ${full.length} مشترك و ${columns.length} عمود`);
  window.__ftthExport = full; // للاطلاع عليها من الـ Console
})();

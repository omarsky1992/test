<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>مزامنة المشتركين</title>
    <style>
        :root { --bg: #fafaf9; --fg: #1c1917; --muted: #78716c; --ok: #15803d; --bad: #b91c1c; --card: #fff; --line: #e7e5e4; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0a09; --fg: #f5f5f4; --muted: #a8a29e; --ok: #4ade80; --bad: #f87171; --card: #1c1917; --line: #292524; } }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.7 system-ui, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 460px; margin: 0 auto; padding: 24px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        #status { font-size: 16px; font-weight: 600; margin: 12px 0 4px; }
        #detail { color: var(--muted); font-size: 13.5px; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
        a { color: #0f766e; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>مزامنة المشتركين من موقع الشركة</h1>
        <div id="status">بانتظار البيانات من صفحة موقع الشركة…</div>
        <div id="detail">اترك هذه النافذة مفتوحة حتى تنتهي المزامنة. لا تغلق صفحة موقع الشركة.</div>
        <p id="done" hidden><a href="{{ \App\Filament\Pages\CompanySyncPage::getUrl() }}" target="_blank">فتح صفحة المزامنة في النظام</a></p>
    </div>
</main>
@include('sync.receiver-script')
<script>
(() => {
    const PANEL = {!! json_encode($panelOrigin, JSON_UNESCAPED_SLASHES) !!};
    const status = (text, cls = '') => { const el = document.getElementById('status'); el.textContent = text; el.className = cls; };
    if (!window.opener) {
        status('افتح هذه النافذة من زر المزامنة في صفحة موقع الشركة.', 'bad');
        return;
    }
    window.subsReceiver({
        onStatus: status,
        onDone: (body) => { document.getElementById('detail').textContent = ''; document.getElementById('done').hidden = false; },
    });
    window.opener.postMessage({ type: 'ready' }, PANEL);
})();
</script>
</body>
</html>

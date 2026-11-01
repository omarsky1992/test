<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $suspended ? 'الحساب موقوف' : 'انتهى الاشتراك' }} – {{ $tenant->name }}</title>
    <style>
        :root { --ink: #1c1b19; --muted: #5e5b53; --accent: #0f766e; --danger: #b91c1c; --bg: #f4f2ec; --card: #fff; }
        @media (prefers-color-scheme: dark) { :root { --ink: #ecebe7; --muted: #a8a59c; --bg: #161513; --card: #22211e; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--ink); font-family: system-ui, 'Segoe UI', Tahoma, sans-serif; }
        main { width: 100%; max-width: 460px; background: var(--card); border-radius: 18px; padding: 28px 24px; box-shadow: 0 10px 30px rgba(0,0,0,.08); text-align: center; }
        .icon { font-size: 44px; }
        h1 { margin: 8px 0 6px; font-size: 22px; color: var(--danger); }
        p { margin: 8px 0; line-height: 1.8; color: var(--muted); }
        .date { font-weight: 700; color: var(--ink); direction: ltr; unicode-bidi: embed; }
        .contact { margin: 18px 0 6px; padding: 14px; border-radius: 12px; background: rgba(15,118,110,.08); color: var(--ink); }
        .contact a { color: var(--accent); font-weight: 700; text-decoration: none; direction: ltr; unicode-bidi: embed; }
        button { margin-top: 14px; font: inherit; padding: 10px 18px; border-radius: 10px; border: 1px solid #d6d1c4; background: transparent; color: var(--ink); cursor: pointer; }
    </style>
</head>
<body>
<main>
    <div class="icon">{{ $suspended ? '⏸️' : '⏳' }}</div>
    <h1>{{ $suspended ? 'الحساب موقوف' : 'انتهى الاشتراك' }}</h1>
    <p><b style="color:var(--ink)">{{ $tenant->name }}</b></p>
    @if ($suspended)
        <p>أوقفت إدارة المنصة هذا الحساب مؤقتاً.</p>
    @elseif ($endedAt)
        <p>انتهى اشتراككم بتاريخ <span class="date">{{ $endedAt }}</span>.</p>
    @endif
    <p>كل بياناتكم محفوظة كما هي: المشتركون والديون والحسابات والسجلات. تعود للعمل فور التجديد.</p>
    <div class="contact">
        <div>{{ $contactMessage }}</div>
        @if ($contactPhone)
            <div style="margin-top:6px">{{ $contactName }}: <a href="tel:{{ $contactPhone }}">{{ $contactPhone }}</a>
                · <a href="https://wa.me/{{ preg_replace('/\D/', '', $contactPhone) }}" target="_blank" rel="noopener">واتساب</a></div>
        @endif
    </div>
    @if ($signedIn)
        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <button type="submit">تسجيل الخروج</button>
        </form>
    @endif
</main>
</body>
</html>

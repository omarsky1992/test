@php use App\Support\Money; @endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سند قبض {{ $payment->receipt_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap">
    <style>
        :root { --ink: #1c1b19; --muted: #5e5b53; --line: #d6d1c4; --accent: #0f766e; --accent-soft: #eef6f6; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'IBM Plex Sans Arabic', sans-serif; color: var(--ink); background: #f4f2ec; }
        .sheet { width: 100%; max-width: 148mm; margin: 16px auto; background: #fff; padding: 28px 30px; position: relative; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding-bottom: 12px; border-bottom: 2px solid var(--ink); }
        .brand { font-size: 18px; font-weight: 700; }
        .sub { font-size: 12px; color: var(--muted); }
        .title { text-align: left; }
        .title h1 { margin: 0; font-size: 20px; }
        .no { font-weight: 700; direction: ltr; font-size: 13px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px dashed var(--line); font-size: 13.5px; }
        .row span:first-child { color: var(--muted); }
        .row span:last-child { font-weight: 600; text-align: left; }
        .amount { margin: 16px 0; border: 2px solid var(--accent); background: var(--accent-soft); border-radius: 12px; padding: 12px 16px; }
        .amount .n { font-size: 26px; font-weight: 700; color: var(--accent); }
        h2 { font-size: 13px; margin: 16px 0 4px; }
        footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 36px; font-size: 13px; }
        .sign { width: 160px; border-top: 1px solid var(--ink); padding-top: 4px; color: var(--muted); text-align: center; }
        .void { position: absolute; inset: 40% 0 auto; text-align: center; font-size: 64px; font-weight: 700; color: rgba(185, 28, 28, .25); transform: rotate(-18deg); pointer-events: none; }
        .toolbar { max-width: 148mm; margin: 0 auto 24px; display: flex; gap: 8px; padding: 0 4px; }
        .toolbar button, .toolbar a { font: inherit; font-size: 14px; font-weight: 600; padding: 10px 16px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
        .toolbar .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .ltr { direction: ltr; unicode-bidi: embed; }
        @media print { body { background: #fff; } .sheet { margin: 0; max-width: none; padding: 0; } .toolbar { display: none; } @page { size: A5; margin: 12mm; } }
    </style>
</head>
<body>
<main class="sheet">
    @if ($payment->status->value === 'voided')
        <div class="void">ملغى</div>
    @endif
    <header>
        <div>
            <div class="brand">{{ $company['name'] }}</div>
            <div class="sub">{{ collect([$payment->account->branch?->name, $company['address'], $company['phone']])->filter()->join(' · ') }}</div>
        </div>
        <div class="title">
            <h1>سند قبض</h1>
            <div class="no">{{ $payment->receipt_number }}</div>
        </div>
    </header>

    <div class="row"><span>التاريخ والوقت</span><span class="ltr">{{ $payment->received_at->format('Y/m/d H:i') }}</span></div>
    <div class="row"><span>استلمنا من</span><span>{{ $payment->subscriber->full_name }}</span></div>
    <div class="row"><span>رقم المشترك (الهاتف)</span><span class="ltr">{{ $payment->subscriber->phone }}</span></div>
    <div class="row"><span>الحساب</span><span class="ltr">{{ $payment->account->username }}</span></div>
    <div class="row"><span>نوع القبض</span><span>{{ $payment->payment_type->getLabel() }}</span></div>
    @forelse ($payment->lines as $line)
        <div class="row">
            <span>{{ $loop->first ? 'طريقة الدفع' : '' }}</span>
            <span>{{ $line->method->name_ar }} · {{ $line->moneyAccount->name }}@if ($line->receiver_name) · {{ $line->receiver_name }}@endif @if ($line->reference)<span class="ltr">({{ $line->reference }})</span>@endif @if ($payment->lines->count() > 1) — {{ Money::format($line->amount, false) }}@endif</span>
        </div>
    @empty
        <div class="row"><span>الطريقة</span><span>{{ $payment->method->getLabel() }} · {{ $payment->moneyAccount->name }}</span></div>
    @endforelse

    <div class="amount">
        <div class="sub">المبلغ</div>
        <div class="n">{{ Money::format($payment->amount) }}</div>
        <div>{{ Money::inWords($payment->amount) }} لا غير</div>
    </div>

    <h2>تفاصيل العملية</h2>
    @forelse ($payment->allocations->where('is_reversed', false) as $allocation)
        <div class="row">
            <span>تسديد الدين {{ $allocation->debt->number }}@if ($allocation->debt->activation) (تفعيل {{ $allocation->debt->activation->kind->getLabel() }})@endif</span>
            <span>{{ Money::format($allocation->amount, false) }}</span>
        </div>
    @empty
        <div class="row"><span>دفع مقدم يُضاف إلى رصيد الحساب</span><span>{{ Money::format($payment->amount, false) }}</span></div>
    @endforelse
    @foreach ($transfers as $transfer)
        <div class="row"><span>نقل الباقي إلى الديون الأولية ({{ $transfer->number }})</span><span>{{ Money::format($transfer->amount, false) }}</span></div>
    @endforeach
    @foreach ($payment->allocations->pluck('debt.activation')->filter()->unique('id') as $activation)
        @if ($activation->completion_status->value === 'completed')
            <div class="row"><span>الاشتراك فعّال حتى</span><span class="ltr">{{ $activation->ends_at->format('Y/m/d H:i') }}</span></div>
        @endif
    @endforeach
    <div class="row"><span><b style="color:var(--ink)">الرصيد المتبقي على الحساب</b></span><span>{{ Money::format($remaining) }}</span></div>

    @if ($payment->notes)
        <p style="font-size:13px">ملاحظات: {{ $payment->notes }}</p>
    @endif
    @if ($payment->status->value === 'voided')
        <p style="font-size:13px;color:#b91c1c">أُلغي بواسطة {{ $payment->voider?->name }} في {{ $payment->voided_at?->format('Y/m/d H:i') }}: {{ $payment->void_reason }}</p>
    @endif

    <footer>
        <div><div class="sub">الموظف</div><b>{{ $payment->creator?->name }}</b></div>
        <div class="sign">التوقيع</div>
    </footer>
</main>
<nav class="toolbar">
    <button type="button" class="primary" onclick="window.print()">طباعة / حفظ PDF</button>
    <a href="https://wa.me/{{ $payment->subscriber->phone_normalized }}?text={{ rawurlencode('سند قبض '.$payment->receipt_number.' بمبلغ '.Money::format($payment->amount)) }}" target="_blank" rel="noopener">إرسال واتساب</a>
    <a href="{{ url()->previous() }}">رجوع</a>
</nav>
</body>
</html>

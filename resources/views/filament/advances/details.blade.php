@php use App\Support\Money; @endphp
<div style="font-size:14px;line-height:1.9">
    <div><b>الموظف:</b> {{ $advance->user->name }}</div>
    <div><b>المبلغ:</b> {{ Money::format($advance->amount) }} · <b>المسدد:</b> {{ Money::format($advance->paid_amount) }} · <b>المتبقي:</b> {{ Money::format($advance->balance) }}</div>
    <div><b>الحالة:</b> {{ $advance->status->getLabel() }}</div>
    <div><b>التاريخ:</b> <span dir="ltr">{{ $advance->advanced_at->format('Y/m/d H:i') }}</span> · <b>صُرفت من:</b> {{ $advance->moneyAccount?->name }}</div>
    <div><b>السبب:</b> {{ $advance->reason }}</div>
    @if ($advance->details)<div><b>التفاصيل:</b> {{ $advance->details }}</div>@endif
    @if ($advance->notes)<div><b>ملاحظات:</b> {{ $advance->notes }}</div>@endif
    <div><b>سجّلها:</b> {{ $advance->creator?->name ?? '—' }}</div>

    <div style="font-weight:700;margin-top:12px">التسديدات</div>
    @forelse ($advance->repayments as $r)
        <div style="border-top:1px solid var(--subs-border);padding:6px 0">
            {{ $r->number }} · <b>{{ Money::format($r->amount) }}</b> · <span dir="ltr">{{ $r->paid_at->format('Y/m/d H:i') }}</span>
            · إلى {{ $r->moneyAccount?->name }} · بواسطة {{ $r->creator?->name ?? '—' }}
            @if ($r->notes)<div style="color:var(--subs-muted)">{{ $r->notes }}</div>@endif
        </div>
    @empty
        <div style="color:var(--subs-muted)">لا توجد تسديدات بعد.</div>
    @endforelse
</div>

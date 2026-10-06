@php
    use App\Support\SubscriberStatus;
    $a = $getRecord();
    $tone = $a->must_activate ? 'violet' : SubscriberStatus::tone($a);
    $dot = match ($tone) {
        'violet' => 'rgb(124 58 237)',
        'red' => 'rgb(220 38 38)',
        'orange' => 'rgb(234 88 12)',
        'green' => 'rgb(22 163 74)',
        default => 'rgb(168 162 158)',
    };
    $days = SubscriberStatus::daysLeft($a);
    $ends = SubscriberStatus::endsAt($a);
    $expired = $ends && $ends->getTimestamp() <= now()->getTimestamp();
    $phone = $a->phone ?? $a->subscriber?->phone;
@endphp
<div class="subs-card subs-tone-{{ $tone }}" style="border:1px solid;border-radius:14px;padding:10px 12px;width:100%">
    <div style="display:flex;align-items:center;gap:10px">
        <div style="min-width:40px;height:40px;border-radius:50%;background:{{ $dot }};color:white;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1">
            @if ($a->must_activate)
                <span style="font-weight:800">✔</span><span style="font-size:9px">سدّد</span>
            @elseif ($days === null)
                <span style="font-weight:800">—</span>
            @elseif ($expired)
                <span style="font-weight:800">0</span><span style="font-size:9px">منتهي</span>
            @else
                <span style="font-weight:800">{{ $days }}</span><span style="font-size:9px">يوم</span>
            @endif
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $a->subscriber?->full_name }}</div>
            <div style="font-size:12px;color:var(--subs-muted)"><span dir="ltr">{{ $a->username }}</span>@if ($phone) · <span dir="ltr">{{ $phone }}</span>@endif</div>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-top:8px;color:var(--subs-text-2)">
        <span>{{ $a->currentPlan?->name_ar ?? $a->external_plan ?? 'بدون فئة' }}@if ($a->currentPlan) · <b>{{ number_format($a->currentPlan->price) }}</b>@endif</span>
        <span>
            @if (! $ends) بلا تاريخ انتهاء
            @elseif ($expired) انتهى {{ $ends->format('m/d') }}
            @else ينتهي {{ $ends->format('m/d') }}
            @endif
        </span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-top:3px">
        <span style="color:var(--subs-amber)">ثانوي: <b>{{ number_format((int) $a->secondary_due) }}</b></span>
        <span style="color:var(--subs-red)">أولي: <b>{{ number_format((int) $a->primary_due) }}</b></span>
    </div>
</div>

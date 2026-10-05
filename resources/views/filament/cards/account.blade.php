@php
    use App\Support\SubscriberStatus;
    $a = $getRecord();
    $tone = $a->must_activate ? 'violet' : SubscriberStatus::tone($a);
    [$bg, $border, $dot] = match ($tone) {
        'violet' => ['rgb(245 243 255)', 'rgb(221 214 254)', 'rgb(124 58 237)'],
        'red' => ['rgb(254 242 242)', 'rgb(254 202 202)', 'rgb(220 38 38)'],
        'orange' => ['rgb(255 247 237)', 'rgb(254 215 170)', 'rgb(234 88 12)'],
        'green' => ['rgb(240 253 244)', 'rgb(187 247 208)', 'rgb(22 163 74)'],
        default => ['white', 'rgb(231 229 228)', 'rgb(168 162 158)'],
    };
    $days = SubscriberStatus::daysLeft($a);
    $ends = SubscriberStatus::endsAt($a);
    $expired = $ends && $ends->getTimestamp() <= now()->getTimestamp();
    $phone = $a->phone ?? $a->subscriber?->phone;
@endphp
<div style="background:{{ $bg }};border:1px solid {{ $border }};border-radius:14px;padding:10px 12px;width:100%">
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
            <div style="font-size:12px;color:rgb(120 113 108)"><span dir="ltr">{{ $a->username }}</span>@if ($phone) · <span dir="ltr">{{ $phone }}</span>@endif</div>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-top:8px;color:rgb(68 64 60)">
        <span>{{ $a->currentPlan?->name_ar ?? $a->external_plan ?? 'بدون فئة' }}@if ($a->currentPlan) · <b>{{ number_format($a->currentPlan->price) }}</b>@endif</span>
        <span>
            @if (! $ends) بلا تاريخ انتهاء
            @elseif ($expired) انتهى {{ $ends->format('m/d') }}
            @else ينتهي {{ $ends->format('m/d') }}
            @endif
        </span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-top:3px">
        <span style="color:rgb(180 83 9)">ثانوي: <b>{{ number_format((int) $a->secondary_due) }}</b></span>
        <span style="color:rgb(185 28 28)">أولي: <b>{{ number_format((int) $a->primary_due) }}</b></span>
    </div>
</div>

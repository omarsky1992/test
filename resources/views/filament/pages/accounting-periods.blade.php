@php
    use App\Support\Money;
    $td = 'padding:6px;border-bottom:1px solid var(--subs-subtle);font-size:13.5px;vertical-align:top';
    $card = 'flex:1;min-width:170px;border:1px solid var(--subs-border);border-radius:14px;padding:12px;background:var(--subs-surface)';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">الفترة المعروضة</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
            <label style="font-size:13px">من<br><input type="date" wire:model.live="from" style="border:1px solid var(--subs-border);border-radius:8px;padding:6px;background:var(--subs-surface);color:var(--subs-text)"></label>
            <label style="font-size:13px">إلى<br><input type="date" wire:model.live="to" style="border:1px solid var(--subs-border);border-radius:8px;padding:6px;background:var(--subs-surface);color:var(--subs-text)"></label>
            <x-filament::button size="sm" color="gray" wire:click="showCurrent('purchases')">المشتريات منذ آخر تصفير ({{ $current['purchases']->setTimezone(config('app.timezone'))->format('m/d H:i') }})</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="showCurrent('sales')">المبيعات منذ آخر تصفير ({{ $current['sales']->setTimezone(config('app.timezone'))->format('m/d H:i') }})</x-filament::button>
        </div>
        <p style="font-size:12.5px;color:var(--subs-muted);margin-top:6px">
            @if ($exact)
                من {{ $rangeFrom->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }} إلى {{ $rangeTo->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}.
            @else
                من {{ $rangeFrom->format('Y/m/d') }} إلى {{ $rangeTo->format('Y/m/d') }} (يشمل اليومين).
            @endif
            يمكن اختيار أي فترة، مثلاً من 15 إلى 14 من الشهر التالي.</p>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px">
            <div style="{{ $card }}"><div style="font-size:12.5px;color:var(--subs-muted)">إجمالي المشتريات ({{ $totals['purchases_count'] }} عملية)</div><div style="font-size:20px;font-weight:800;color:var(--subs-red)">{{ Money::format($totals['purchases']) }}</div>
                <div style="font-size:12px;color:var(--subs-muted)">مصروفات {{ Money::format($totals['breakdown']['expenses']['total'], false) }} · شحن رصيد الشركة {{ Money::format($totals['breakdown']['company_topups']['total'], false) }}</div></div>
            <div style="{{ $card }}"><div style="font-size:12.5px;color:var(--subs-muted)">إجمالي المبيعات ({{ $totals['sales_count'] }} عملية)</div><div style="font-size:20px;font-weight:800;color:var(--subs-green)">{{ Money::format($totals['sales']) }}</div>
                <div style="font-size:12px;color:var(--subs-muted)">تفعيلات {{ Money::format($totals['breakdown']['activations']['total'], false) }} · بيع أجهزة {{ Money::format($totals['breakdown']['device_sales']['total'], false) }}</div></div>
            <div style="{{ $card }}"><div style="font-size:12.5px;color:var(--subs-muted)">الفرق بين المبيعات والمشتريات</div><div style="font-size:20px;font-weight:800">{{ Money::format($totals['difference']) }}</div>
                <div style="font-size:12px;color:var(--subs-muted)">ليس صافي الربح: لا يشمل باقي المصاريف والتكاليف.</div></div>
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        @foreach (['purchases' => 'تفاصيل المشتريات', 'sales' => 'تفاصيل المبيعات'] as $key => $title)
            <x-filament::section :heading="$title" collapsible>
                <div style="max-height:360px;overflow:auto">
                    <table style="width:100%;border-collapse:collapse">
                        <tbody>
                            @forelse ($operations[$key] as $op)
                                <tr>
                                    <td style="{{ $td }};white-space:nowrap">{{ $op['at']?->setTimezone(config('app.timezone'))->format('m/d H:i') }}</td>
                                    <td style="{{ $td }}"><b>{{ $op['kind'] }}</b> <span style="color:var(--subs-muted)">{{ $op['number'] }}</span><div style="font-size:12px;color:var(--subs-muted)">{{ $op['details'] }}</div></td>
                                    <td style="{{ $td }};text-align:end;font-weight:700">{{ Money::format($op['amount'], false) }}</td>
                                </tr>
                            @empty
                                <tr><td style="{{ $td }}">لا توجد عمليات.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="الفترات المغلقة (سجل التصفير)" collapsible>
        @if ($closings->isEmpty())
            <p style="font-size:13.5px;color:var(--subs-muted)">لم تُغلق أي فترة بعد.</p>
        @else
            <div style="overflow:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr>
                        @foreach (['النوع', 'من', 'إلى', 'المجموع', 'العمليات', 'أغلقها', 'وقت الإغلاق', ''] as $h)<th style="{{ $td }};text-align:start">{{ $h }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($closings as $c)
                            <tr>
                                <td style="{{ $td }}"><b>{{ \App\Models\PeriodClosing::SCOPES[$c->scope] }}</b>@if ($c->batch) <span style="font-size:11px;color:var(--subs-muted)">(معاً)</span>@endif</td>
                                <td style="{{ $td }}">{{ $c->starts_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }}">{{ $c->ends_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }};font-weight:700">{{ Money::format($c->total) }}</td>
                                <td style="{{ $td }}">{{ $c->operations }}</td>
                                <td style="{{ $td }}">{{ $c->closer?->name ?? '—' }}</td>
                                <td style="{{ $td }}">{{ $c->closed_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }}"><x-filament::link tag="button" wire:click="showClosing({{ $c->id }})">عرض التقرير</x-filament::link></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

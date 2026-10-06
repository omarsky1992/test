@php
    use App\Support\Money;
    use App\Filament\Pages\EmployeeStatement;
    $th = 'padding:8px 6px;text-align:right;font-weight:600;color:var(--subs-muted);border-bottom:1px solid var(--subs-border);white-space:nowrap';
    $td = 'padding:8px 6px;border-bottom:1px solid var(--subs-subtle);vertical-align:top';
    $empty = '<p style="color:var(--subs-muted);font-size:14px">لا توجد بيانات في هذه الفترة.</p>';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">من
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">إلى
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </label>
            <x-filament::button color="gray" icon="heroicon-o-printer" onclick="window.print()">طباعة</x-filament::button>
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">العهد الموجودة مع الموظفين الآن</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--subs-amber)">{{ Money::format($report['custody_total']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">السلف المستحقة على الموظفين</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--subs-red)">{{ Money::format($report['advances_outstanding']) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="تحصيلات الموظفين">
        @if (! $report['collections']) {!! $empty !!} @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:560px">
                <thead><tr><th style="{{ $th }}">الموظف</th><th style="{{ $th }}">عدد السندات</th><th style="{{ $th }}">المجموع</th><th style="{{ $th }}">منها في عهدته</th><th style="{{ $th }}"></th></tr></thead>
                <tbody>@foreach ($report['collections'] as $r)
                    <tr><td style="{{ $td }}">{{ $r['name'] }}</td><td style="{{ $td }}">{{ $r['receipts'] }}</td><td style="{{ $td }};font-weight:600">{{ Money::format($r['total']) }}</td>
                        <td style="{{ $td }}">{{ Money::format($r['to_custody']) }}</td>
                        <td style="{{ $td }}"><a href="{{ EmployeeStatement::getUrl(['user' => $r['user_id']]) }}" style="color:rgb(15 118 110);font-weight:600">كشف الحساب</a></td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="العهد الموجودة مع الموظفين (حتى الآن)">
        @if (! $report['custody']) {!! $empty !!} @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:400px">
                <thead><tr><th style="{{ $th }}">الموظف</th><th style="{{ $th }}">العهدة الحالية</th></tr></thead>
                <tbody>@foreach ($report['custody'] as $r)
                    <tr><td style="{{ $td }}">{{ $r['name'] }}</td><td style="{{ $td }};font-weight:600">{{ Money::format($r['balance']) }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="المبالغ المسلَّمة للصندوق">
        @if (! $report['handovers']) {!! $empty !!} @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:620px">
                <thead><tr><th style="{{ $th }}">الوقت</th><th style="{{ $th }}">الرقم</th><th style="{{ $th }}">الموظف</th><th style="{{ $th }}">المبلغ</th><th style="{{ $th }}">إلى</th><th style="{{ $th }}">أكّدها</th></tr></thead>
                <tbody>@foreach ($report['handovers'] as $r)
                    <tr><td style="{{ $td }}" dir="ltr">{{ $r['at']->format('Y/m/d H:i') }}</td><td style="{{ $td }}">{{ $r['number'] }}</td><td style="{{ $td }}">{{ $r['name'] }}</td>
                        <td style="{{ $td }};font-weight:600">{{ Money::format($r['amount']) }}</td><td style="{{ $td }}">{{ $r['into'] }}</td><td style="{{ $td }}">{{ $r['by'] }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="السلف">
        @if ($report['advances']->isEmpty()) {!! $empty !!} @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:720px">
                <thead><tr><th style="{{ $th }}">التاريخ</th><th style="{{ $th }}">الرقم</th><th style="{{ $th }}">الموظف</th><th style="{{ $th }}">المبلغ</th><th style="{{ $th }}">المتبقي</th><th style="{{ $th }}">الحالة</th><th style="{{ $th }}">السبب</th><th style="{{ $th }}">سجّلها</th></tr></thead>
                <tbody>@foreach ($report['advances'] as $a)
                    <tr><td style="{{ $td }}" dir="ltr">{{ $a->advanced_at->format('Y/m/d H:i') }}</td><td style="{{ $td }}">{{ $a->number }}</td><td style="{{ $td }}">{{ $a->user->name }}</td>
                        <td style="{{ $td }};font-weight:600">{{ Money::format($a->amount) }}</td><td style="{{ $td }}">{{ Money::format($a->balance) }}</td>
                        <td style="{{ $td }}"><x-filament::badge :color="$a->status->getColor()">{{ $a->status->getLabel() }}</x-filament::badge></td>
                        <td style="{{ $td }}">{{ $a->reason }}</td><td style="{{ $td }}">{{ $a->creator?->name }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="تسديد السلف">
        @if ($report['repayments']->isEmpty()) {!! $empty !!} @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:620px">
                <thead><tr><th style="{{ $th }}">الوقت</th><th style="{{ $th }}">الرقم</th><th style="{{ $th }}">الموظف</th><th style="{{ $th }}">السلفة</th><th style="{{ $th }}">المبلغ</th><th style="{{ $th }}">إلى</th><th style="{{ $th }}">سجّلها</th></tr></thead>
                <tbody>@foreach ($report['repayments'] as $r)
                    <tr><td style="{{ $td }}" dir="ltr">{{ $r->paid_at->format('Y/m/d H:i') }}</td><td style="{{ $td }}">{{ $r->number }}</td><td style="{{ $td }}">{{ $r->user->name }}</td>
                        <td style="{{ $td }}">{{ $r->advance->number }}</td><td style="{{ $td }};font-weight:600">{{ Money::format($r->amount) }}</td><td style="{{ $td }}">{{ $r->moneyAccount?->name }}</td><td style="{{ $td }}">{{ $r->creator?->name }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

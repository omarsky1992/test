@php
    use App\Support\Money;
    $th = 'padding:8px 6px;text-align:right;font-weight:600;color:rgb(120 113 108);border-bottom:1px solid rgb(231 229 228);white-space:nowrap';
    $td = 'padding:8px 6px;border-bottom:1px solid rgb(245 245 244);vertical-align:top';
    $signed = fn (int $v) => $v === 0 ? '' : ($v > 0 ? '+'.number_format($v) : '−'.number_format(-$v));
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">الموظف
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="user">
                        @foreach ($employees as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">من
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">إلى
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </label>
            <x-filament::button color="gray" icon="heroicon-o-printer" onclick="window.print()">طباعة</x-filament::button>
        </div>
    </x-filament::section>

    <div style="font-size:18px;font-weight:700">{{ $employee->name }} <span style="font-size:13px;font-weight:500;color:rgb(120 113 108)">· {{ $periodLabel }}</span></div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
        @foreach ([
            ['إجمالي التحصيلات', $summary['collections'], null],
            ['المسلَّم للصندوق', $summary['handed_over'], null],
            ['عهدة التحصيل الحالية', $summary['custody'], 'rgb(180 83 9)'],
            ['إجمالي السلف', $summary['advances'], null],
            ['تسديد السلف', $summary['repaid'], null],
            ['السلف المتبقية', $summary['advances_remaining'], 'rgb(185 28 28)'],
        ] as [$label, $value, $color])
            <x-filament::section>
                <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">{{ $label }}</div>
                <div style="font-size:22px;font-weight:700;margin-top:4px;{{ $color ? "color:$color" : '' }}">{{ Money::format($value) }}</div>
            </x-filament::section>
        @endforeach
    </div>
    <p style="font-size:12.5px;color:rgb(120 113 108);margin-top:-8px">العهدة الحالية والسلف المتبقية دائماً حتى اليوم. العهدة والسلف حسابان منفصلان لا يُخصم أحدهما من الآخر.</p>

    <x-filament::section heading="الحركات">
        @if ($rows->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لا توجد حركات.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:760px">
                    <thead><tr>
                        <th style="{{ $th }}">التاريخ والوقت</th><th style="{{ $th }}">النوع</th><th style="{{ $th }}">التفاصيل</th>
                        <th style="{{ $th }}">العهدة</th><th style="{{ $th }}">السلف</th><th style="{{ $th }}">سجّلها / أكّدها</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td style="{{ $td }}" dir="ltr">{{ $r['at']->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }};white-space:nowrap">{{ $r['label'] }}</td>
                                <td style="{{ $td }}">{{ $r['details'] }}</td>
                                <td style="{{ $td }};font-weight:600;color:{{ $r['custody'] < 0 ? 'rgb(21 128 61)' : 'rgb(180 83 9)' }}" dir="ltr">{{ $signed($r['custody']) }}</td>
                                <td style="{{ $td }};font-weight:600;color:{{ $r['advance'] < 0 ? 'rgb(21 128 61)' : 'rgb(185 28 28)' }}" dir="ltr">{{ $signed($r['advance']) }}</td>
                                <td style="{{ $td }}">{{ $r['by'] ?? 'تلقائي' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

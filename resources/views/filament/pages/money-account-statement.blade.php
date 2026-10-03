@php
    use App\Support\Money;
    $th = 'padding:8px 6px;text-align:right;font-weight:600;color:rgb(120 113 108);border-bottom:1px solid rgb(231 229 228);white-space:nowrap';
    $td = 'padding:8px 6px;border-bottom:1px solid rgb(245 245 244);vertical-align:top';
    $n = fn (int $v) => $v === 0 ? '' : number_format($v);
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">الصندوق
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="box">
                        @foreach ($boxes as $id => $name)
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

    @if (! $statement)
        <p style="color:rgb(120 113 108)">لا توجد صناديق.</p>
    @else
        <div style="font-size:18px;font-weight:700">{{ $account->name }} <span style="font-size:13px;font-weight:500;color:rgb(120 113 108)">· {{ $periodLabel }}</span></div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px">
            @foreach ([
                ['الرصيد في بداية الفترة', $statement['opening'], null],
                ['الداخل', $statement['total_in'], 'rgb(21 128 61)'],
                ['الخارج', $statement['total_out'], 'rgb(185 28 28)'],
                ['الرصيد في نهاية الفترة', $statement['closing'], null],
                ['الرصيد الحالي', $statement['current'], 'rgb(15 118 110)'],
            ] as [$label, $value, $color])
                <x-filament::section>
                    <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">{{ $label }}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:4px;{{ $color ? "color:$color" : '' }}" dir="ltr">{{ Money::format($value) }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="الحركات">
            @if ($statement['rows']->isEmpty())
                <p style="color:rgb(120 113 108);font-size:14px">لا توجد حركات في هذه الفترة.</p>
            @else
                @if ($statement['truncated'])
                    <p style="color:rgb(180 83 9);font-size:13px">عُرضت أول {{ number_format($statement['rows']->count()) }} حركة فقط؛ ضيّق الفترة لرؤية الباقي.</p>
                @endif
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:820px">
                        <thead><tr>
                            <th style="{{ $th }}">التاريخ والوقت</th><th style="{{ $th }}">النوع</th><th style="{{ $th }}">المصدر والتفاصيل</th>
                            <th style="{{ $th }}">داخل</th><th style="{{ $th }}">خارج</th><th style="{{ $th }}">الرصيد الناتج</th><th style="{{ $th }}">المستخدم</th>
                        </tr></thead>
                        <tbody>
                            <tr>
                                <td style="{{ $td }}" colspan="5"><b>الرصيد السابق</b></td>
                                <td style="{{ $td }};font-weight:700" dir="ltr">{{ number_format($statement['opening']) }}</td><td style="{{ $td }}"></td>
                            </tr>
                            @foreach ($statement['rows'] as $r)
                                <tr>
                                    <td style="{{ $td }}" dir="ltr">{{ $r['at']->format('Y/m/d H:i') }}</td>
                                    <td style="{{ $td }};white-space:nowrap">{{ $r['label'] }}</td>
                                    <td style="{{ $td }}">{{ $r['details'] }}</td>
                                    <td style="{{ $td }};font-weight:600;color:rgb(21 128 61)" dir="ltr">{{ $n($r['in']) }}</td>
                                    <td style="{{ $td }};font-weight:600;color:rgb(185 28 28)" dir="ltr">{{ $n($r['out']) }}</td>
                                    <td style="{{ $td }};font-weight:700" dir="ltr">{{ number_format($r['balance']) }}</td>
                                    <td style="{{ $td }}">{{ $r['by'] ?? 'تلقائي' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>

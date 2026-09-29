@php
    use App\Support\Money;
    $voidLabels = ['payment.voided' => 'سندات ملغاة', 'debt.voided' => 'ديون محذوفة', 'activation.voided' => 'تفعيلات ملغاة', 'expense.voided' => 'مصروفات ملغاة'];
    $table = function (array $rows) { return $rows; };
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
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">الموظف
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="user">
                        <option value="">الكل</option>
                        @foreach ($users as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <x-filament::button color="gray" icon="heroicon-o-printer" onclick="window.print()">طباعة</x-filament::button>
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        @foreach ([
            ['مجموع المقبوضات', Money::format($report['collections']['total']), $report['collections']['count'].' عملية'],
            ['التفعيلات', $report['activations']['count'], 'بقيمة '.Money::format($report['activations']['value'])],
            ['ديون جديدة', Money::format($report['debts']['secondary'] + $report['debts']['primary']), 'ثانوي '.Money::format($report['debts']['secondary'], false).' · أولي '.Money::format($report['debts']['primary'], false)],
            ['المناقلات', $report['transfers']['count'], Money::format($report['transfers']['total'])],
            ['الإكمال +23 يوم', $report['activations']['completed_by_payment'] + $report['activations']['completed_by_transfer'], 'بالتسديد '.$report['activations']['completed_by_payment'].' · بالمناقلة '.$report['activations']['completed_by_transfer']],
            ['المصروفات', Money::format($report['expenses']['total']), 'الراجع المستلم '.Money::format($report['settlements'])],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">{{ $label }}</div>
                <div style="font-size:24px;font-weight:700;margin-top:4px">{{ $value }}</div>
                <div style="font-size:12px;color:rgb(120 113 108)">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        @foreach ([
            'المقبوضات حسب الطريقة' => $report['collections']['by_method'],
            'المقبوضات حسب الصندوق / المستلم' => $report['collections']['by_box'],
            'المقبوضات حسب الموظف' => $report['collections']['by_user'],
            'المقبوضات حسب النوع' => $report['collections']['by_type'],
            'التفعيلات حسب الفئة' => $report['activations']['by_plan'],
            'المصروفات حسب الفئة' => $report['expenses']['by_category'],
        ] as $title => $rows)
            <x-filament::section :heading="$title">
                @if (empty($rows))
                    <p style="color:rgb(120 113 108);font-size:13px">لا توجد عمليات.</p>
                @else
                    <table style="width:100%;font-size:14px;border-collapse:collapse">
                        @foreach ($rows as $row)
                            <tr style="border-bottom:1px solid rgb(231 229 228)">
                                <td style="padding:6px 0">{{ $row['label'] }}</td>
                                <td style="padding:6px 0;color:rgb(120 113 108);text-align:center">{{ $row['count'] }}</td>
                                <td style="padding:6px 0;font-weight:600;text-align:left">{{ Money::format($row['total']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <x-filament::section heading="الأرصدة الآن">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <tr style="border-bottom:1px solid rgb(231 229 228)"><td style="padding:6px 0">الديون الثانوية</td><td style="text-align:left;font-weight:600">{{ Money::format($balances['secondary']) }}</td></tr>
                <tr style="border-bottom:1px solid rgb(231 229 228)"><td style="padding:6px 0">الديون الأولية</td><td style="text-align:left;font-weight:600">{{ Money::format($balances['primary']) }}</td></tr>
                @foreach ($balances['boxes'] as $box)
                    <tr style="border-bottom:1px solid rgb(231 229 228)"><td style="padding:6px 0">{{ $box['name'] }}</td><td style="text-align:left;font-weight:600">{{ Money::format($box['balance']) }}</td></tr>
                @endforeach
            </table>
        </x-filament::section>
        <x-filament::section heading="الإلغاءات والتصحيحات">
            @forelse ($report['voids'] as $action => $count)
                <div style="display:flex;justify-content:space-between;font-size:14px;padding:6px 0">{{ $voidLabels[$action] ?? $action }} <b>{{ $count }}</b></div>
            @empty
                <p style="color:rgb(120 113 108);font-size:13px">لا توجد إلغاءات.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>

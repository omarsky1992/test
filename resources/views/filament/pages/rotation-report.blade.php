@php use App\Support\Money; @endphp
<x-filament-panels::page>
    <x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13.5px;font-weight:600">
                الكاش (إن وجد)
                <input type="text" inputmode="numeric" wire:model.live.debounce.500ms="cash" placeholder="مثال: 250000" dir="ltr"
                       style="padding:9px 11px;border:1px solid var(--subs-border);border-radius:10px;background:var(--subs-surface);color:inherit;font:inherit">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13.5px;font-weight:600">
                رصيد الصفحة (إن وجد)
                <input type="text" inputmode="numeric" wire:model.live.debounce.500ms="pageBalance" placeholder="مثال: 79471" dir="ltr"
                       style="padding:9px 11px;border:1px solid var(--subs-border);border-radius:10px;background:var(--subs-surface);color:inherit;font:inherit">
            </label>
        </div>
        <p style="font-size:12.5px;color:var(--subs-muted);margin:8px 0 0">تُكتب باليد ولا تُحفظ ولا تغيّر أي رصيد: تدخل في مجموع هذا التقرير فقط.</p>
    </x-filament::section>

    <x-filament::section heading="📊 تقرير الدوّار">
        <table style="width:100%;font-size:15px;border-collapse:collapse">
            <tbody>
                @foreach ($report['lines'] as $line)
                    <tr>
                        <td style="padding:9px 6px;border-bottom:1px solid var(--subs-subtle)">{{ $line['label'] }}</td>
                        <td style="padding:9px 6px;border-bottom:1px solid var(--subs-subtle);text-align:left;font-weight:700;{{ $line['amount'] < 0 ? 'color:var(--subs-red)' : '' }}" dir="ltr">{{ Money::format($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:12px 6px;font-weight:800;font-size:17px">المجموع</td>
                    <td data-test="rotation-total" style="padding:12px 6px;text-align:left;font-weight:800;font-size:19px;color:{{ $report['total'] < 0 ? 'var(--subs-red)' : 'var(--subs-green)' }}" dir="ltr">{{ Money::format($report['total']) }}</td>
                </tr>
            </tbody>
        </table>
        <p style="font-size:12.5px;color:var(--subs-muted);margin:8px 0 0">الربح والخسارة منذ آخر تصفير (المبيعات − المشتريات)، كما في الرئيسية. الأرقام لحظية عند فتح الصفحة.</p>
    </x-filament::section>
</x-filament-panels::page>

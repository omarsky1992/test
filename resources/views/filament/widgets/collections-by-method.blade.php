@php use App\Support\Money; @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="'الاستلام حسب طريقة الدفع — '.$label">
        @if ($rows === [])
            <p style="font-size:13.5px;color:rgb(120 113 108)">لا توجد مقبوضات في هذه الفترة.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:12px">
                @foreach ($rows as $row)
                    @php $share = $total > 0 ? round($row['total'] * 100 / $total) : 0; @endphp
                    <div>
                        <div style="display:flex;gap:8px;font-size:13.5px">
                            <span style="font-weight:600">{{ $row['label'] }}</span>
                            <span style="color:rgb(120 113 108)">{{ $row['count'] }} عملية</span>
                            <span style="margin-inline-start:auto;font-weight:700">{{ Money::format($row['total']) }}</span>
                            <span style="color:rgb(120 113 108);min-width:40px;text-align:left">{{ $share }}%</span>
                        </div>
                        <div style="height:8px;border-radius:4px;background:rgba(120,113,108,.15);margin-top:6px;overflow:hidden" role="img" aria-label="{{ $row['label'] }} {{ $share }}%">
                            <div style="height:100%;width:{{ $share }}%;background:#2a78d6;border-radius:4px"></div>
                        </div>
                    </div>
                @endforeach
                <div style="display:flex;font-size:13.5px;border-top:1px solid rgba(120,113,108,.2);padding-top:8px">
                    <span style="font-weight:600">المجموع</span>
                    <span style="margin-inline-start:auto;font-weight:700">{{ Money::format($total) }}</span>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

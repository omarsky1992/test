@php $td = 'padding:7px;border-bottom:1px solid var(--subs-subtle);font-size:14px'; @endphp
<x-filament-widgets::widget>
    <x-filament::section heading="ملخص المخزون">
        @if ($rows->isEmpty())
            <p style="font-size:13.5px;color:var(--subs-muted)">لا توجد كارتات بعد. أضفها من «إضافة كارتات».</p>
        @else
            <div style="overflow:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr>@foreach (['النوع', 'الفئة', 'الإجمالي', 'المتوفر', 'المفعّل', 'ملغى'] as $h)<th style="{{ $td }};text-align:start">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td style="{{ $td }}"><b>{{ \App\Models\VoucherCard::TYPES[$r->type] ?? $r->type }}</b></td>
                                <td style="{{ $td }}">{{ number_format($r->denomination) }} د.ع</td>
                                <td style="{{ $td }}">{{ $r->total }}</td>
                                <td style="{{ $td }};color:var(--subs-green);font-weight:800">{{ $r->available }}</td>
                                <td style="{{ $td }}">{{ $r->activated }}</td>
                                <td style="{{ $td }};color:var(--subs-muted)">{{ $r->voided }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

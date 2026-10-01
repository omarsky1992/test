@php
    use App\Support\Money;
    $mask = fn (int $v) => $hidden ? '••••••' : Money::format($v);
@endphp
<x-filament-widgets::widget>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
        <x-filament::section>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:600;color:rgb(120 113 108)">الرصيد الكلي</span>
                <button type="button" wire:click="toggle"
                        aria-label="{{ $hidden ? 'إظهار الرصيد' : 'إخفاء الرصيد' }}" title="{{ $hidden ? 'إظهار الرصيد' : 'إخفاء الرصيد' }}"
                        style="margin-inline-start:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:8px;border:1px solid rgb(214 211 209);background:transparent;cursor:pointer;font:inherit;font-size:12.5px;color:inherit">
                    <x-filament::icon :icon="$hidden ? 'heroicon-o-eye' : 'heroicon-o-eye-slash'" class="h-4 w-4" />
                    {{ $hidden ? 'إظهار' : 'إخفاء' }}
                </button>
            </div>
            <div style="font-size:30px;font-weight:700;margin-top:6px;letter-spacing:.01em">{{ $mask($total) }}</div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12.5px;color:rgb(120 113 108);margin-top:6px">
                <span>القاصة: <b>{{ $mask($cash) }}</b></span>
                <span>المحافظ: <b>{{ $mask($electronic) }}</b></span>
                <span>رصيد الشركة: <b>{{ $mask($company) }}</b></span>
                <span>عهد الموظفين: <b>{{ $mask($custody) }}</b></span>
            </div>
        </x-filament::section>

        @if ($showEmployees)
            <x-filament::section>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:13px;font-weight:600;color:rgb(180 83 9)">العهد مع الموظفين</span>
                    <x-filament::badge color="gray" size="sm" style="margin-inline-start:auto">ضمن الرصيد الكلي</x-filament::badge>
                </div>
                <div style="font-size:26px;font-weight:700;margin-top:6px">{{ $mask($custody) }}</div>
                <div style="font-size:12.5px;color:rgb(120 113 108);margin-top:6px">مع {{ $custodyHolders }} موظف · لم تُسلَّم للصندوق بعد</div>
            </x-filament::section>

            <x-filament::section>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:13px;font-weight:600;color:rgb(185 28 28)">السلف المستحقة على الموظفين</span>
                    <x-filament::badge color="gray" size="sm" style="margin-inline-start:auto">للعرض فقط</x-filament::badge>
                </div>
                <div style="font-size:26px;font-weight:700;margin-top:6px">{{ $mask($advances) }}</div>
                <div style="font-size:12.5px;color:rgb(120 113 108);margin-top:6px">{{ $advancesCount }} سلفة · غير داخلة في الرصيد الكلي</div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:600;color:rgb(180 83 9)">الديون الثانوية</span>
                <x-filament::badge color="gray" size="sm" style="margin-inline-start:auto">للعرض فقط</x-filament::badge>
            </div>
            <div style="font-size:26px;font-weight:700;margin-top:6px">{{ Money::format($secondary) }}</div>
            <div style="font-size:12.5px;color:rgb(120 113 108);margin-top:6px">{{ $secondaryAccounts }} حساب · غير داخلة في الرصيد الكلي</div>
        </x-filament::section>

        <x-filament::section>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:600;color:rgb(3 105 161)">الديون الأولية</span>
                <x-filament::badge color="gray" size="sm" style="margin-inline-start:auto">للعرض فقط</x-filament::badge>
            </div>
            <div style="font-size:26px;font-weight:700;margin-top:6px">{{ Money::format($primary) }}</div>
            <div style="font-size:12.5px;color:rgb(120 113 108);margin-top:6px">{{ $primaryAccounts }} حساب · غير داخلة في الرصيد الكلي</div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>

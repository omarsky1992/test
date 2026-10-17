@php use App\Support\Money; @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">💳 رصيد البطاقات</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
            <div>
                <div style="font-size:13px;color:var(--subs-muted)">الرصيد الكلي المستلم على البطاقات</div>
                <div data-test="cards-total" style="font-size:26px;font-weight:800;color:{{ $total < 0 ? 'var(--subs-red)' : 'var(--subs-green)' }}">{{ Money::format($total) }}</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                {{ $this->resetAllAction }}
                @if ($detailsUrl)
                    <x-filament::button tag="a" :href="$detailsUrl" color="gray" icon="heroicon-o-credit-card">التفاصيل والحركات</x-filament::button>
                @endif
            </div>
        </div>
        @if ($cards->isEmpty())
            <p style="font-size:13.5px;color:var(--subs-muted);margin-top:8px">لا توجد بطاقات. أضفها من «الصناديق» كمحفظة إلكترونية.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-top:12px">
                @foreach ($cards as $card)
                    <div style="border:1px solid var(--subs-border);border-radius:12px;padding:8px 10px;background:var(--subs-surface)">
                        <div style="font-size:13px;color:var(--subs-text-2)">{{ $card['name'] }}</div>
                        <div style="font-weight:800">{{ Money::format($card['balance']) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-widgets::widget>

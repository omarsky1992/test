@php
    $chip = 'font-size:13px;font-weight:700;padding:5px 12px;border-radius:999px;border:1px solid rgb(231 229 228);background:white;cursor:pointer';
    $chipOn = 'font-size:13px;font-weight:700;padding:5px 12px;border-radius:999px;border:1px solid var(--primary-600);background:var(--primary-600);color:white;cursor:pointer';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. اختر المشتركين</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" style="{{ $filter === $key ? $chipOn : $chip }}">{{ $label }}</button>
            @endforeach
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="اسم، هاتف، يوزر…" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button size="sm" color="gray" wire:click="selectAll">تحديد الكل</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="clearSelection">إلغاء التحديد</x-filament::button>
        </div>
        @if ($accounts->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لا يوجد مشتركون في هذا التصنيف.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;max-height:360px;overflow:auto">
                @foreach ($accounts as $a)
                    <label style="display:flex;gap:8px;align-items:center;border:1px solid rgb(231 229 228);border-radius:12px;padding:7px 10px;cursor:pointer;background:white">
                        <x-filament::input.checkbox wire:model.live="selected" value="{{ $a->id }}" />
                        <span style="min-width:0">
                            <b style="font-size:13.5px">{{ $a->subscriber?->full_name }}</b>
                            <span style="display:block;font-size:12px;color:rgb(120 113 108)">
                                {{ \App\Filament\Pages\WhatsappReminders::phone($a) ? '' : 'بدون رقم · ' }}باقي {{ \App\Support\SubscriberStatus::daysLeft($a) ?? '—' }} يوم · عليه {{ number_format((int) $a->open_due) }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @if ($accounts->count() >= $limit)
                <p style="font-size:12px;color:rgb(120 113 108);margin-top:6px">يُعرض أول {{ $limit }} فقط، استخدم البحث للباقي.</p>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">2. اختر الرسالة</x-slot>
        @if ($templates->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لا توجد رسائل. يضيفها المدير من «قوالب الرسائل».</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
                @foreach ($templates as $t)
                    <label style="display:flex;gap:8px;align-items:center;border:1px solid {{ $chosenTemplate?->id === $t->id ? 'rgb(22 163 74)' : 'rgb(231 229 228)' }};background:{{ $chosenTemplate?->id === $t->id ? 'rgb(240 253 244)' : 'white' }};border-radius:12px;padding:9px 12px;cursor:pointer;font-weight:700;font-size:14px">
                        <input type="radio" wire:model.live="template" value="{{ $t->id }}"> {{ $t->icon }} {{ $t->title }}
                    </label>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">3. أرسل ({{ $messages->count() }})</x-slot>
        @if ($messages->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">حدّد مشتركاً واحداً على الأقل ورسالة.</p>
        @else
            <p style="font-size:13px;color:rgb(120 113 108);margin-bottom:8px">كل زر يفتح واتساب برسالة جاهزة لهذا المشترك؛ اضغط إرسال في واتساب ثم ارجع للتالي.</p>
            <div style="display:flex;flex-direction:column;gap:8px">
                @foreach ($messages as $m)
                    <div style="border:1px solid rgb(231 229 228);border-radius:14px;padding:10px 12px;background:white">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                            <b>{{ $m['account']->subscriber?->full_name }}</b>
                            @if ($m['url'])
                                <a href="{{ $m['url'] }}" target="_blank" rel="noopener" wire:click="opened({{ $m['account']->id }})"
                                    style="background:rgb(22 163 74);color:white;border-radius:10px;padding:6px 14px;font-weight:800;font-size:13px;text-decoration:none;white-space:nowrap">فتح واتساب ←</a>
                            @else
                                <span style="font-size:12.5px;color:rgb(185 28 28)">لا يوجد رقم هاتف</span>
                            @endif
                        </div>
                        <div style="margin-top:6px;background:rgb(220 248 198);border-radius:10px;padding:8px 10px;font-size:13px;white-space:pre-wrap">{{ $m['text'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

@php
    $chip = 'font-size:13px;font-weight:700;padding:5px 12px;border-radius:999px;border:1px solid var(--subs-border);background:var(--subs-surface);cursor:pointer';
    $chipOn = 'font-size:13px;font-weight:700;padding:5px 12px;border-radius:999px;border:1px solid var(--primary-600);background:var(--primary-600);color:white;cursor:pointer';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. حدّد المستلمين</x-slot>
        <x-slot name="description">تُرسل الرسالة إلى من تحددهم فقط. اختر مجموعة ثم حدّد الأسماء، أو ابحث عن مشترك.</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
            @foreach ($groups as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" style="{{ $filter === $key ? $chipOn : $chip }}">{{ $label }}</button>
            @endforeach
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="اسم، هاتف، يوزر، رقم المشترك…" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button size="sm" color="gray" wire:click="selectAll">تحديد كل المجموعة</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="clearSelection">إلغاء التحديد</x-filament::button>
        </div>
        @if ($accounts->isEmpty())
            <p style="color:var(--subs-muted);font-size:14px">لا يوجد مشتركون هنا.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px;max-height:340px;overflow:auto">
                @foreach ($accounts as $a)
                    <label wire:key="bc-{{ $a->id }}" class="{{ \App\Support\SubscriberFlags::hasNotes($a->subscriber_id) ? 'subs-note-box' : '' }}" style="display:flex;gap:8px;align-items:center;border:1px solid var(--subs-border);border-radius:12px;padding:7px 10px;cursor:pointer;background:var(--subs-surface)">
                        <x-filament::input.checkbox wire:model.live="selected" value="{{ $a->id }}" />
                        <span style="min-width:0">
                            <b style="font-size:13.5px">{{ $a->subscriber?->full_name }} {{ \App\Support\SubscriberFlags::badges($a->subscriber_id) }}</b>
                            <span style="display:block;font-size:12px;color:var(--subs-muted)" dir="ltr">{{ \App\Filament\Pages\WhatsappReminders::phone($a) ?? 'بدون رقم' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @if ($accounts->count() >= $shown)
                <p style="font-size:12px;color:var(--subs-muted);margin-top:6px">يُعرض أول {{ $shown }}؛ «تحديد كل المجموعة» يحدد الكل (حتى {{ \App\WhatsApp\BroadcastService::MAX_RECIPIENTS }}).</p>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">2. الرسالة</x-slot>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">3. المعاينة والإرسال</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:16px;font-size:14px;margin-bottom:10px">
            <span>المستلمون المحددون: <b>{{ $count }}</b></span>
            <span>لهم رقم هاتف: <b>{{ $withPhone }}</b></span>
            <span>الصورة: <b>{{ $imageMode === 'image' ? 'تُرسل كصورة' : 'تُرسل كرابط' }}</b></span>
        </div>
        @if ($withPhone > $dailyLimit)
            <p style="font-size:13px;color:var(--subs-amber);margin-bottom:8px">العدد أكبر من الحد اليومي ({{ $dailyLimit }}): يكمل الإرسال في الأيام التالية.</p>
        @endif
        @if ($preview)
            <div style="font-size:12.5px;color:var(--subs-muted);margin-bottom:4px">كما تصل إلى {{ $previewName }}:</div>
            <div style="max-width:420px;background:var(--subs-bubble);border-radius:12px;padding:8px 10px;font-size:13.5px;white-space:pre-wrap">@if (! empty($data['image']))🖼️ <i>{{ $imageMode === 'image' ? '(الصورة المرفقة)' : '(رابط الصورة)' }}</i>
@endif{{ $preview }}</div>
        @else
            <p style="color:var(--subs-muted);font-size:14px">حدّد مستلماً واكتب الرسالة لتظهر المعاينة.</p>
        @endif
        <div style="margin-top:12px">
            <x-filament::button color="success" icon="heroicon-o-paper-airplane" wire:click="send" :disabled="$count === 0"
                wire:confirm="إرسال الرسالة إلى {{ $withPhone }} مشترك محدد؟">إرسال ({{ $withPhone }})</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>

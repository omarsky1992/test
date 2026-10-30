@php
    $field = 'padding:9px 11px;border:1px solid var(--subs-border);border-radius:10px;background:var(--subs-surface);color:inherit;font:inherit';
    $label = 'display:flex;flex-direction:column;gap:4px;font-size:13.5px;font-weight:600';
    $statuses = \App\Models\WhatsappOutbox::STATUSES;
@endphp
<x-filament-panels::page>
    <x-filament::section heading="🧪 رسالة تجريبية">
        <p style="font-size:12.5px;color:var(--subs-muted);margin:0 0 10px">بيئة اختبار فقط: البيانات تجريبية، لا ترتبط بأي مشترك أو دين أو حساب، ولا تغيّر أي شيء.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <label style="{{ $label }}">
                الرقم الذي تصله
                <input type="text" inputmode="tel" wire:model="phone" placeholder="9647701234567" dir="ltr" data-test="test-phone" style="{{ $field }}">
            </label>
            <label style="{{ $label }}">
                الرسالة
                <select wire:model.live="event" style="{{ $field }}">
                    @foreach ($events as $key => $title)
                        <option value="{{ $key }}">{{ $title }}</option>
                    @endforeach
                </select>
            </label>
            <label style="{{ $label }}">
                يُرسل من
                <select wire:model="line" style="{{ $field }}">
                    @foreach ($lines as $key => $title)
                        <option value="{{ $key }}">{{ $title }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:12px">
            <label style="{{ $label }}">الاسم <input type="text" wire:model.live.debounce.500ms="name" style="{{ $field }}"></label>
            <label style="{{ $label }}">اسم الجهاز <input type="text" wire:model.live.debounce.500ms="device" dir="ltr" style="{{ $field }}"></label>
            <label style="{{ $label }}">الفئة <input type="text" wire:model.live.debounce.500ms="plan" dir="ltr" style="{{ $field }}"></label>
            <label style="{{ $label }}">الأيام <input type="text" inputmode="numeric" wire:model.live.debounce.500ms="days" dir="ltr" style="{{ $field }}"></label>
            <label style="{{ $label }}">المبلغ <input type="text" inputmode="numeric" wire:model.live.debounce.500ms="amount" dir="ltr" style="{{ $field }}"></label>
        </div>
        @if ($event === 'custom')
            <label style="{{ $label }};margin-top:12px">
                النص (يقبل نفس الفراغات مثل {الاسم} {الأيام})
                <textarea rows="4" wire:model.live.debounce.500ms="custom" style="{{ $field }}"></textarea>
            </label>
        @endif
    </x-filament::section>

    <x-filament::section heading="📱 هكذا تصل">
        @if ($text !== null)
            <div data-test="test-preview" style="white-space:pre-wrap;background:#dcf8c6;color:#111;border-radius:12px;padding:12px 14px;max-width:420px;font-size:14.5px;line-height:1.7">{{ $text }}</div>
        @else
            <p style="color:var(--subs-muted)">لا يوجد قالب فعّال لهذه الرسالة. فعّله من «قوالب الرسائل» أو اختر «نص حر».</p>
        @endif
        <div style="margin-top:12px">
            <x-filament::button wire:click="send" icon="heroicon-o-paper-airplane" color="success">إرسال الاختبار</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section heading="آخر رسائل الاختبار">
        @forelse ($recent as $message)
            <div style="display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid var(--subs-subtle);font-size:13.5px">
                <span dir="ltr">{{ $message->to_phone }}</span>
                <span>{{ $statuses[$message->status] ?? $message->status }}@if ($message->error) – {{ \Illuminate\Support\Str::limit($message->error, 60) }}@endif</span>
                <span style="color:var(--subs-muted)">{{ $message->created_at?->setTimezone(config('app.timezone'))->format('m/d H:i') }}</span>
            </div>
        @empty
            <p style="color:var(--subs-muted);font-size:13.5px">لا توجد رسائل اختبار بعد.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

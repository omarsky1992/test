@php
    $active = $notes->where('status', 'active');
    $closed = $notes->where('status', 'closed');
@endphp
<div style="display:flex;flex-direction:column;gap:8px">
    @if (\App\Support\SubscriberFlags::blacklisted($subscriber?->id))
        <div style="padding:8px 10px;border-radius:10px;background:var(--subs-red-bg, rgba(220,38,38,.1));color:var(--subs-red);font-size:13.5px">⛔ هذا المشترك في القائمة السوداء (للمتابعة الداخلية فقط).</div>
    @endif
    @forelse ($active as $note)
        <div style="border:1px solid var(--subs-border);border-inline-start:4px solid var(--subs-amber);border-radius:10px;padding:8px 10px">
            <div style="white-space:pre-line;font-size:14px">{{ $note->body }}</div>
            <div style="font-size:12px;color:var(--subs-muted);margin-top:4px">{{ $note->creator?->name ?? '—' }} · {{ $note->created_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }} · <b style="color:var(--subs-amber)">نشطة</b>@if ($note->remind_at) · ⏰ {{ $note->remind_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}{{ $note->reminded_at ? ' (أُرسل)' : '' }}@endif</div>
        </div>
    @empty
        <div style="font-size:13.5px;color:var(--subs-muted)">لا توجد ملاحظات نشطة.</div>
    @endforelse
    @if ($closed->isNotEmpty())
        <details>
            <summary style="cursor:pointer;font-size:13px;color:var(--subs-text-2)">الملاحظات المنتهية ({{ $closed->count() }})</summary>
            @foreach ($closed as $note)
                <div style="border:1px dashed var(--subs-border);border-radius:10px;padding:6px 10px;margin-top:6px;opacity:.8">
                    <div style="white-space:pre-line;font-size:13.5px">{{ $note->body }}</div>
                    <div style="font-size:12px;color:var(--subs-muted)">{{ $note->creator?->name ?? '—' }} · {{ $note->created_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }} · منتهية {{ $note->closed_at?->setTimezone(config('app.timezone'))->format('Y/m/d') }}{{ $note->closer ? ' ('.$note->closer->name.')' : '' }}</div>
                </div>
            @endforeach
        </details>
    @endif
    @if ($subscriber)
        <a href="{{ \App\Support\SubscriberFlags::notesUrl($subscriber->id) }}" style="font-size:13px;color:var(--subs-link, rgb(15 118 110))">كل الملاحظات وإنهاؤها ←</a>
    @endif
</div>

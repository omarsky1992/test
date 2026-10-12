@php
    $td = 'padding:6px;border-bottom:1px solid var(--subs-subtle);font-size:13.5px;vertical-align:top';
    $colors = ['sent' => 'var(--subs-green)', 'failed' => 'var(--subs-red)', 'skipped' => 'var(--subs-muted)'];
@endphp
<div>
    <div style="background:var(--subs-bubble);border-radius:12px;padding:8px 10px;font-size:13.5px;white-space:pre-wrap;margin-bottom:10px">@if ($broadcast->image_path)🖼️ <i>صورة مرفقة</i>
@endif{{ $broadcast->body }}</div>
    <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="{{ $td }};text-align:start">المشترك</th><th style="{{ $td }};text-align:start">الرقم</th><th style="{{ $td }};text-align:start">الحالة</th><th style="{{ $td }};text-align:start">أُرسلت</th></tr></thead>
        <tbody>
            @forelse ($messages as $m)
                <tr>
                    <td style="{{ $td }}">{{ $m->account?->subscriber?->full_name ?? '—' }}</td>
                    <td style="{{ $td }}" dir="ltr">{{ $m->to_phone }}</td>
                    <td style="{{ $td }};color:{{ $colors[$m->status] ?? 'var(--subs-amber)' }};font-weight:700">{{ \App\Models\WhatsappOutbox::STATUSES[$m->status] ?? $m->status }}@if ($m->error)<div style="font-weight:400;font-size:12px;color:var(--subs-muted)">{{ mb_strimwidth($m->error, 0, 120, '…') }}</div>@endif</td>
                    <td style="{{ $td }}">{{ $m->sent_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td style="{{ $td }}" colspan="4">لا يوجد مستلمون.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

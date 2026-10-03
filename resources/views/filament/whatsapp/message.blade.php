@php
    $row = 'display:grid;grid-template-columns:130px 1fr;gap:8px;padding:6px 0;border-bottom:1px solid rgb(245 245 244);font-size:14px';
    $label = 'color:rgb(120 113 108);font-weight:600';
    $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
@endphp
<div>
    <div style="{{ $row }}"><span style="{{ $label }}">الوقت</span><span dir="ltr" style="text-align:right">{{ $message->received_at->format('Y/m/d H:i:s') }}</span></div>
    <div style="{{ $row }}"><span style="{{ $label }}">الرقم</span><span dir="ltr" style="text-align:right">{{ $message->from_phone }}</span></div>
    <div style="{{ $row }}"><span style="{{ $label }}">المستخدم</span><span>{{ $message->user?->name ?? '—' }}</span></div>
    <div style="{{ $row }}"><span style="{{ $label }}">النتيجة</span><span>{{ \App\Models\WhatsappMessage::STATUSES[$message->status] ?? $message->status }}</span></div>
    @if ($message->body)
        <div style="{{ $row }}"><span style="{{ $label }}">النص</span><span style="white-space:pre-wrap">{{ $message->body }}</span></div>
    @endif
    @if ($message->transcript !== null)
        <div style="{{ $row }}"><span style="{{ $label }}">نص الرسالة الصوتية</span><span style="white-space:pre-wrap">{{ $message->transcript }}</span></div>
    @endif
    @if ($message->command)
        <div style="{{ $row }}"><span style="{{ $label }}">ما فهمه النظام</span><pre dir="ltr" style="font-size:12px;white-space:pre-wrap;margin:0">{{ $json($message->command) }}</pre></div>
    @endif
    @if ($message->result)
        <div style="{{ $row }}"><span style="{{ $label }}">البيانات التي تغيرت</span><pre dir="ltr" style="font-size:12px;white-space:pre-wrap;margin:0">{{ $json($message->result) }}</pre></div>
    @endif
    <div style="{{ $row }}"><span style="{{ $label }}">الرد</span><span style="white-space:pre-wrap">{{ $message->reply ?? '—' }}</span></div>
    @if ($message->error)
        <div style="{{ $row }}"><span style="{{ $label }}">الخطأ</span><span style="white-space:pre-wrap;color:rgb(185 28 28)">{{ $message->error }}</span></div>
    @endif
</div>

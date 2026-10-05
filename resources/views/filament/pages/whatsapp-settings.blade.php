@php
    $td = 'padding:8px 6px;border-bottom:1px solid rgb(245 245 244);vertical-align:top;font-size:14px';
    $status = $link['status'] ?? null;
    $color = match ($status) { 'WORKING' => 'rgb(21 128 61)', 'SCAN_QR_CODE', 'STARTING' => 'rgb(180 83 9)', default => 'rgb(185 28 28)' };
@endphp
<x-filament-panels::page>
    @if ($qrMode)
        <x-filament::section heading="ربط الهاتف بالباركود" description="أي هاتف عليه واتساب: افتح واتساب ← الأجهزة المرتبطة ← ربط جهاز، وامسح الباركود. يُفضَّل رقم مخصص للنظام وليس رقمك الأساسي.">
            <div @if (in_array($status, ['SCAN_QR_CODE', 'STARTING'], true)) wire:poll.5s @endif style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start">
                <div style="flex:1;min-width:240px">
                    <div style="font-size:15px;margin-bottom:8px">الحالة:
                        <b style="color:{{ $color }}">{{ $statusLabels[$status] ?? $status }}</b>
                    </div>
                    @if ($status === 'WORKING')
                        <div style="font-size:14px;margin-bottom:10px">الهاتف المربوط: <b dir="ltr">{{ $link['phone'] }}</b> {{ $link['name'] ? '('.$link['name'].')' : '' }}</div>
                    @elseif ($status === 'UNREACHABLE')
                        <div style="font-size:13.5px;color:rgb(120 113 108);margin-bottom:10px">خدمة الربط لا تعمل على السيرفر. شغّل التحديث: <code dir="ltr">bash ~/subs/deploy/update.sh</code></div>
                    @endif
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @if ($status !== 'WORKING')
                            <x-filament::button wire:click="linkPhone" icon="heroicon-o-qr-code">ربط هاتف (إظهار الباركود)</x-filament::button>
                        @endif
                        <x-filament::button color="gray" wire:click="restartPhone" icon="heroicon-o-arrow-path">إعادة تشغيل الاتصال</x-filament::button>
                        @if ($status === 'WORKING')
                            <x-filament::button color="danger" wire:click="unlinkPhone" wire:confirm="فصل الهاتف المربوط؟ لن تُرسل أو تُستقبل رسائل حتى تربط هاتفاً من جديد." icon="heroicon-o-link-slash">فصل الهاتف</x-filament::button>
                        @endif
                    </div>
                    <div style="display:flex;gap:16px;margin-top:14px;font-size:13.5px;color:rgb(87 83 78)">
                        <span>بانتظار الإرسال: <b>{{ $outbox['pending'] }}</b></span>
                        <span>أُرسلت اليوم: <b>{{ $outbox['sent_today'] }}</b></span>
                        <span>فشلت: <b style="color:rgb(185 28 28)">{{ $outbox['failed'] }}</b></span>
                    </div>
                </div>
                @if ($status === 'SCAN_QR_CODE')
                    <div style="text-align:center">
                        <img src="{{ route('whatsapp.qr') }}?t={{ now()->timestamp }}" alt="باركود الربط" style="width:260px;height:260px;border:1px solid rgb(231 229 228);border-radius:12px;background:white">
                        <div style="font-size:12px;color:rgb(120 113 108);margin-top:4px">يتجدد تلقائياً</div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="مفاتيح الخادم" description="تُكتب في deploy/.env على السيرفر فقط، ولا تظهر قيمها هنا. مفاتيح خدمة الباركود يولّدها update.sh تلقائياً." collapsible collapsed>
        <table style="width:100%;border-collapse:collapse">
            <tbody>
                @foreach ($checks as [$env, $purpose, $ok])
                    <tr>
                        <td style="{{ $td }};width:28px">{!! $ok ? '<span style="color:rgb(21 128 61)">✔</span>' : '<span style="color:rgb(185 28 28)">✘</span>' !!}</td>
                        <td style="{{ $td }}">{{ $purpose }}</td>
                        <td style="{{ $td }};font-family:monospace;font-size:12.5px" dir="ltr">{{ $env }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @unless ($qrMode)
            <p style="font-size:13px;margin-top:8px">رابط الـ Webhook في Meta: <code dir="ltr">{{ $webhookUrl }}</code></p>
        @endunless
    </x-filament::section>

    {{ $this->form }}
</x-filament-panels::page>

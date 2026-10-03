@php
    $td = 'padding:8px 6px;border-bottom:1px solid rgb(245 245 244);vertical-align:top;font-size:14px';
@endphp
<x-filament-panels::page>
    <x-filament::section heading="الربط بالخادم" description="المفاتيح تُكتب في ملف deploy/.env على السيرفر فقط، ولا تظهر هنا قيمها.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <tbody>
                    @foreach ($checks as [$env, $purpose, $ok])
                        <tr>
                            <td style="{{ $td }};width:28px">{!! $ok ? '<span style="color:rgb(21 128 61)">✔</span>' : '<span style="color:rgb(185 28 28)">✘</span>' !!}</td>
                            <td style="{{ $td }}">{{ $purpose }}</td>
                            <td style="{{ $td }};font-family:monospace;font-size:12.5px" dir="ltr">{{ $env }}</td>
                            <td style="{{ $td }};color:{{ $ok ? 'rgb(21 128 61)' : 'rgb(185 28 28)' }}">{{ $ok ? 'مضبوط' : 'غير مضبوط' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;font-size:14px">
            <div style="font-weight:600;margin-bottom:4px">رابط الـ Webhook (Callback URL) في Meta:</div>
            <code dir="ltr" style="display:inline-block;padding:6px 10px;background:rgb(245 245 244);border-radius:6px;font-size:13px;user-select:all">{{ $webhookUrl }}</code>
            <p style="color:rgb(120 113 108);font-size:13px;margin-top:6px">اشترك في حقل <span dir="ltr">messages</span>. الأوامر تُقبل فقط من الأرقام المضافة في «الأرقام المصرح لها».</p>
        </div>
    </x-filament::section>

    {{ $this->form }}
</x-filament-panels::page>

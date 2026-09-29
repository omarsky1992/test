@php
    $size = fn (?int $b) => $b === null ? '—' : ($b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format($b / 1024).' KB');
    $stale = $last === null || $last->started_at->lt(now()->subHours(36));
@endphp
<x-filament-panels::page>
    @unless ($configured)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" heading="خطوة إعداد لمرة واحدة">
            <p style="font-size:14px;line-height:1.8">
                لربط Google Drive يجب أولاً إنشاء «مفتاح OAuth» مجاني في Google Cloud، ووضع القيمتين
                <code>GOOGLE_CLIENT_ID</code> و<code>GOOGLE_CLIENT_SECRET</code> في إعدادات الاستضافة.
                الشرح خطوة بخطوة في <b>docs/free-hosting.md</b>. عنوان الرجوع الذي تضيفه في Google هو:
            </p>
            <p dir="ltr" style="font-family:monospace;font-size:13px;background:rgb(245 245 244);padding:8px 12px;border-radius:8px;margin-top:8px;user-select:all">{{ $redirectUri }}</p>
        </x-filament::section>
    @endunless

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">Google Drive</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $connected ? 'rgb(21 128 61)' : 'rgb(185 28 28)' }}">
                {{ $connected ? 'مربوط' : 'غير مربوط' }}
            </div>
            <div style="font-size:13px;color:rgb(120 113 108)" dir="ltr">{{ $email ?? '—' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">آخر نسخة ناجحة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $stale ? 'rgb(185 28 28)' : 'inherit' }}">
                {{ $last?->started_at->format('Y/m/d H:i') ?? 'لا توجد بعد' }}
            </div>
            <div style="font-size:13px;color:rgb(120 113 108)">{{ $last ? $size($last->size_bytes) : 'اضغط «نسخ الآن» بعد الربط' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">الجدول</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px">يومياً 3:00 فجراً</div>
            <div style="font-size:13px;color:rgb(120 113 108)">تُحفظ آخر {{ $keepDays }} يوماً في مجلد «{{ config('backup.google.folder_name') }}»</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="سجل النسخ">
        @if ($runs->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لم تُنفَّذ أي نسخة بعد.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:14px;border-collapse:collapse;min-width:560px">
                    <thead>
                        <tr style="text-align:right;color:rgb(120 113 108);border-bottom:1px solid rgb(231 229 228)">
                            <th style="padding:8px 4px">الوقت</th><th>الحالة</th><th>الملف</th><th>الحجم</th><th>بواسطة</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr style="border-bottom:1px solid rgb(245 245 244)">
                                <td style="padding:8px 4px" dir="ltr">{{ $run->started_at->format('Y/m/d H:i') }}</td>
                                <td>
                                    <x-filament::badge :color="['success' => 'success', 'failed' => 'danger', 'running' => 'warning'][$run->status]">
                                        {{ ['success' => 'نجحت', 'failed' => 'فشلت', 'running' => 'جارية'][$run->status] }}
                                    </x-filament::badge>
                                </td>
                                <td dir="ltr" style="font-size:12px">{{ $run->file_name ?? '—' }}
                                    @if ($run->error)<div dir="rtl" style="color:rgb(185 28 28);font-size:12px">{{ $run->error }}</div>@endif
                                </td>
                                <td>{{ $size($run->size_bytes) }}</td>
                                <td>{{ $run->user?->name ?? 'تلقائي' }}</td>
                                <td>@if ($run->drive_link)<a href="{{ $run->drive_link }}" target="_blank" rel="noopener" style="color:rgb(15 118 110);font-weight:600">فتح في Drive</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

@php
    $size = fn (?int $b) => $b === null ? '—' : ($b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format($b / 1024).' KB');
    $stale = $last === null || $last->started_at->lt(now()->subHours(36));
@endphp
<x-filament-panels::page>
    @unless ($configured)
        <x-filament::section icon="heroicon-o-information-circle" icon-color="info" heading="Google Drive غير مضبوط (اختياري)" collapsible collapsed>
            <p style="font-size:14px;line-height:1.8">
                لربط Google Drive يجب أولاً إنشاء «مفتاح OAuth» مجاني في Google Cloud، ووضع القيمتين
                <code>GOOGLE_CLIENT_ID</code> و<code>GOOGLE_CLIENT_SECRET</code> في إعدادات الاستضافة.
                الشرح خطوة بخطوة في <b>docs/free-hosting.md</b>. عنوان الرجوع الذي تضيفه في Google هو:
            </p>
            <p dir="ltr" style="font-family:monospace;font-size:13px;background:var(--subs-subtle);padding:8px 12px;border-radius:8px;margin-top:8px;user-select:all">{{ $redirectUri }}</p>
        </x-filament::section>
    @endunless

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">Google Drive</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $connected ? 'var(--subs-green)' : 'var(--subs-red)' }}">
                {{ $connected ? 'مربوط' : 'غير مربوط' }}
            </div>
            <div style="font-size:13px;color:var(--subs-muted)" dir="ltr">{{ $email ?? '—' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">آخر نسخة ناجحة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $stale ? 'var(--subs-red)' : 'inherit' }}">
                {{ $last?->started_at->format('Y/m/d H:i') ?? 'لا توجد بعد' }}
            </div>
            <div style="font-size:13px;color:var(--subs-muted)">{{ $last ? $size($last->size_bytes) : 'اضغط «نسخ الآن» بعد الربط' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">الجدول</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px">يومياً 3:00 فجراً</div>
            <div style="font-size:13px;color:var(--subs-muted)">تُحفظ آخر {{ $keepDays }} يوماً في مجلد «{{ config('backup.google.folder_name') }}»</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="النسخ اليومية التلقائية على السيرفر">
        @if ($serverBackups === [])
            <p style="color:var(--subs-muted);font-size:14px">لا توجد نسخ تلقائية ظاهرة. على خادم Docker تُحفظ نسخة يومية في <span dir="ltr">deploy/backups</span>؛ حدّث النظام لتظهر هنا. ويمكنك دائماً الضغط على «تنزيل نسخة الآن».</p>
        @else
            <p style="color:var(--subs-muted);font-size:13px;margin-top:0">نسخة كل يوم تلقائياً (آخر 7 أيام، و4 أسابيع، و6 أشهر). نزّل نسخة إلى حاسوبك من وقت لآخر، فإذا تعطّل السيرفر تبقى معك.</p>
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:14px;border-collapse:collapse;min-width:480px">
                    <thead><tr style="text-align:right;color:var(--subs-muted);border-bottom:1px solid var(--subs-border)">
                        <th style="padding:8px 4px">الوقت</th><th>النوع</th><th>الملف</th><th>الحجم</th><th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($serverBackups as $b)
                            <tr style="border-bottom:1px solid var(--subs-subtle)">
                                <td style="padding:8px 4px" dir="ltr">{{ $b['at']->format('Y/m/d H:i') }}</td>
                                <td>{{ $b['kind'] }}</td>
                                <td dir="ltr" style="font-size:12px">{{ $b['name'] }}</td>
                                <td>{{ $size($b['size']) }}</td>
                                <td><a href="{{ route('backup.server', explode('/', $b['id'], 2)) }}" style="color:rgb(15 118 110);font-weight:600">تنزيل</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="سجل النسخ">
        @if ($runs->isEmpty())
            <p style="color:var(--subs-muted);font-size:14px">لم تُنفَّذ أي نسخة بعد.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:14px;border-collapse:collapse;min-width:560px">
                    <thead>
                        <tr style="text-align:right;color:var(--subs-muted);border-bottom:1px solid var(--subs-border)">
                            <th style="padding:8px 4px">الوقت</th><th>الحالة</th><th>الملف</th><th>الحجم</th><th>بواسطة</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr style="border-bottom:1px solid var(--subs-subtle)">
                                <td style="padding:8px 4px" dir="ltr">{{ $run->started_at->format('Y/m/d H:i') }}</td>
                                <td>
                                    <x-filament::badge :color="['success' => 'success', 'failed' => 'danger', 'running' => 'warning'][$run->status]">
                                        {{ ['success' => 'نجحت', 'failed' => 'فشلت', 'running' => 'جارية'][$run->status] }}
                                    </x-filament::badge>
                                </td>
                                <td dir="ltr" style="font-size:12px">{{ $run->file_name ?? '—' }}
                                    @if ($run->error)<div dir="rtl" style="color:var(--subs-red);font-size:12px">{{ $run->error }}</div>@endif
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

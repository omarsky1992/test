@php
    use App\Support\Money;
    $statusColor = ['success' => 'success', 'failed' => 'danger', 'running' => 'warning'];
    $statusLabel = ['success' => 'نجحت', 'failed' => 'فشلت', 'running' => 'جارية'];
    $th = 'padding:8px 6px;text-align:right;font-weight:600;color:rgb(120 113 108);border-bottom:1px solid rgb(231 229 228);white-space:nowrap';
    $td = 'padding:8px 6px;border-bottom:1px solid rgb(245 245 244);vertical-align:top';
@endphp
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-bookmark" icon-color="primary" heading="المزامنة من المتصفح (الطريقة المعتمدة)">
        <p style="font-size:14px;line-height:1.9;margin:0">
            موقع الشركة لا يقبل الاتصال من سيرفرات خارج العراق، لذلك تتم المزامنة من متصفحك وأنت مسجّل الدخول في موقع الشركة.
            لا يُحفظ أي باسورد، ولا تُرسَل إلى الموقع إلا طلبات قراءة.
        </p>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin:14px 0">
            <a href="{{ $bookmarklet }}" onclick="event.preventDefault(); alert('اسحب هذا الزر إلى شريط الإشارات المرجعية في كروم، ثم اضغطه وأنت في صفحة موقع الشركة.')"
               style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:#0f766e;color:#fff;font-weight:700;text-decoration:none;cursor:grab">
                ⟳ مزامنة المشتركين
            </a>
            <span style="font-size:13px;color:rgb(120 113 108)">← اسحب هذا الزر بالماوس إلى شريط الإشارات في كروم (مرة واحدة فقط)</span>
        </div>
        <ol style="font-size:14px;line-height:2;margin:0;padding-inline-start:20px">
            <li>إذا لم يظهر شريط الإشارات: اضغط <b dir="ltr">Ctrl+Shift+B</b>.</li>
            <li>افتح <b dir="ltr">admin.ftth.iq</b> وسجّل الدخول كالمعتاد.</li>
            <li>اضغط زر <b>مزامنة المشتركين</b> من شريط الإشارات. تفتح نافذة صغيرة من النظام وتظهر خانة خضراء في صفحة الشركة تبيّن التقدم.</li>
            <li>اترك الصفحتين حتى تظهر «تمت ✓» (دقيقة إلى دقيقتين). يُنصح بالمزامنة مرتين يومياً.</li>
        </ol>
        <p style="font-size:13px;color:rgb(120 113 108);margin:10px 0 0">
            آخر مزامنة من المتصفح: <span dir="ltr">{{ $lastBrowser?->started_at->format('Y/m/d H:i') ?? '—' }}</span>
            · إذا تغيّر عنوان النظام (الدومين) اسحب الزر من جديد.
        </p>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">المزامنة التلقائية</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $enabled ? 'rgb(21 128 61)' : 'rgb(120 113 108)' }}">{{ $enabled ? "كل {$interval} دقيقة" : 'متوقفة' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">آخر مزامنة ناجحة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px" dir="ltr">{{ $last?->started_at->format('Y/m/d H:i') ?? '—' }}</div>
            @if ($last)
                <div style="font-size:13px;color:rgb(120 113 108)">{{ $last->stats['received'] ?? 0 }} سجل · {{ $last->stats['renewals'] ?? 0 }} تجديد</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">جلسة موقع الشركة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $hasRefreshToken ? 'rgb(21 128 61)' : 'rgb(120 113 108)' }}">{{ $hasRefreshToken ? 'متصلة' : 'غير متصلة' }}</div>
            <div style="font-size:13px;color:rgb(120 113 108)" dir="ltr">{{ $refreshedAt ? 'آخر تجديد '.$refreshedAt->format('Y/m/d H:i') : '' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:rgb(120 113 108);font-weight:600">قاعدة التجديد</div>
            <div style="font-size:13.5px;margin-top:4px;line-height:1.7">آخر أيام محفوظة = 0 والموقع الآن أكثر من 0 ← تجديد + دين ثانوي بسعر الفئة، مرة واحدة لكل تجديد.</div>
        </x-filament::section>
    </div>

    @if ($previewRows !== null)
        <x-filament::section heading="معاينة (لم يُحفظ شيء)">
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13px;border-collapse:collapse;min-width:900px">
                    <thead><tr>
                        @foreach (['معرف المشترك', 'الاسم', 'الهاتف', 'الاشتراك', 'الحالة', 'الانتهاء', 'المنطقة', 'GPS', 'اسم الجهاز', 'ONT', 'FAT', 'المنفذ', 'معرف الاشتراك'] as $h)
                            <th style="{{ $th }}">{{ $h }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($previewRows as $r)
                            <tr>
                                @foreach (['customer_id', 'name', 'phone', 'plan', 'status', 'ends_at', 'zone', 'gps', 'username', 'serial', 'fat', 'port', 'subscription_id'] as $k)
                                    <td style="{{ $td }}" @if (in_array($k, ['phone', 'ends_at', 'gps', 'username', 'serial'])) dir="ltr" @endif>{{ $r[$k] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="13" style="{{ $td }}">لم يرجع الموقع أي سجل.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="التجديدات المكتشفة">
        @if ($renewals->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لا توجد تجديدات بعد.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:720px">
                    <thead><tr>
                        <th style="{{ $th }}">وقت الاكتشاف</th><th style="{{ $th }}">المشترك</th><th style="{{ $th }}">الجهاز</th>
                        <th style="{{ $th }}">الأيام</th><th style="{{ $th }}">الفئة</th><th style="{{ $th }}">الدين الثانوي</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($renewals as $r)
                            <tr>
                                <td style="{{ $td }}" dir="ltr">{{ $r->detected_at->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }}"><a href="{{ \App\Filament\Resources\Subscribers\SubscriberResource::getUrl('view', ['record' => $r->subscriber_id]) }}" style="color:rgb(15 118 110);font-weight:600">{{ $r->subscriber?->full_name }}</a></td>
                                <td style="{{ $td }}" dir="ltr">{{ $r->account?->username }}</td>
                                <td style="{{ $td }}">{{ $r->previous_days }} ← {{ $r->new_days }}</td>
                                <td style="{{ $td }}">{{ $r->plan_name ?? '—' }}</td>
                                <td style="{{ $td }}">
                                    @if ($r->debt)
                                        {{ $r->debt->number }} · {{ Money::format($r->amount) }}
                                    @else
                                        <x-filament::badge color="warning">بدون دين: الفئة غير معروفة</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="سجل المزامنة">
        @if ($runs->isEmpty())
            <p style="color:rgb(120 113 108);font-size:14px">لم تُنفَّذ أي مزامنة بعد.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:720px">
                    <thead><tr>
                        <th style="{{ $th }}">الوقت</th><th style="{{ $th }}">الحالة</th><th style="{{ $th }}">السجلات</th><th style="{{ $th }}">جديد</th>
                        <th style="{{ $th }}">محدَّث</th><th style="{{ $th }}">تجديدات / ديون</th><th style="{{ $th }}">أخطاء</th><th style="{{ $th }}">بواسطة</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($runs as $run)
                            @php $s = $run->stats ?? []; @endphp
                            <tr>
                                <td style="{{ $td }}" dir="ltr">{{ $run->started_at->format('Y/m/d H:i') }}</td>
                                <td style="{{ $td }}"><x-filament::badge :color="$statusColor[$run->status]">{{ $statusLabel[$run->status] }}</x-filament::badge>
                                    @if ($run->error)<div style="color:rgb(185 28 28);font-size:12px;margin-top:4px">{{ $run->error }}</div>@endif
                                </td>
                                <td style="{{ $td }}">{{ $s['received'] ?? 0 }}</td>
                                <td style="{{ $td }}">{{ ($s['subscribers_created'] ?? 0) }} مشترك · {{ ($s['accounts_created'] ?? 0) }} حساب</td>
                                <td style="{{ $td }}">{{ $s['accounts_updated'] ?? 0 }}</td>
                                <td style="{{ $td }}">{{ $s['renewals'] ?? 0 }} / {{ $s['debts_created'] ?? 0 }}</td>
                                <td style="{{ $td }}">
                                    @php $errors = $s['errors'] ?? []; @endphp
                                    @if ($errors)
                                        <details><summary style="cursor:pointer;color:rgb(185 28 28)">{{ count($errors) }}</summary>
                                            <ul style="font-size:12px;margin-top:4px">@foreach ($errors as $e)<li>{{ $e['username'] ?? $e['customer_id'] ?? '—' }}: {{ $e['message'] }}</li>@endforeach</ul>
                                        </details>
                                    @else 0 @endif
                                </td>
                                <td style="{{ $td }}">{{ $run->creator?->name ?? 'تلقائي' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

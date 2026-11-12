@php
    use App\Support\Money;
    $statusColor = ['success' => 'success', 'failed' => 'danger', 'running' => 'warning'];
    $statusLabel = ['success' => 'نجحت', 'failed' => 'فشلت', 'running' => 'جارية'];
    $th = 'padding:8px 6px;text-align:right;font-weight:600;color:var(--subs-muted);border-bottom:1px solid var(--subs-border);white-space:nowrap';
    $td = 'padding:8px 6px;border-bottom:1px solid var(--subs-subtle);vertical-align:top';
@endphp
<x-filament-panels::page>
    @if ($firstImport)
        <x-filament::section icon="heroicon-o-arrow-down-tray" icon-color="success" heading="استيراد المشتركين من موقع الشركة (أول مرة)">
            <p style="font-size:14px;line-height:1.9;margin:0">
                النظام فارغ. الاستيراد يجلب كل مشتركيك من موقع الشركة (الفعّالين والمنتهين) مع أرقامهم والفات والبورت والموقع وتواريخ الانتهاء،
                بدون أي دين أو حركة مالية. أول مرة قد يأخذ بضع دقائق حسب عدد المشتركين.
            </p>
            <ol style="font-size:14px;line-height:2;margin:10px 0;padding-inline-start:20px">
                <li><a href="{{ route('sync.extension') }}" style="color:#0f766e;font-weight:700">نزّل إضافة كروم الخاصة بنظامك</a> ونصّبها (الخطوات في «تنصيب إضافة كروم» بالأسفل).</li>
                <li>سجّل الدخول في موقع الشركة admin.ftth.iq بنفس المتصفح.</li>
                <li>اضغط الزر وانتظر حتى تظهر النتيجة هنا.</li>
            </ol>
            <button type="button" id="subs-import-start"
                    style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:10px;border:0;background:#15803d;color:#fff;font:inherit;font-weight:700;font-size:16px;cursor:pointer">
                ⬇ استيراد المشتركين الآن
            </button>
        </x-filament::section>
    @endif
    <x-filament::section icon="heroicon-o-arrow-path" icon-color="primary" heading="المزامنة من موقع الشركة">
        <p style="font-size:14px;line-height:1.9;margin:0">
            موقع الشركة لا يقبل الاتصال من سيرفرات خارج العراق، لذلك تتم المزامنة من متصفحك بجلستك في موقع الشركة.
            لا يُحفظ أي باسورد، ولا تُرسَل إلى الموقع إلا طلبات قراءة.
        </p>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin:16px 0 6px">
            <button type="button" id="subs-sync-start"
                    style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;border:0;background:#0f766e;color:#fff;font:inherit;font-weight:700;font-size:15px;cursor:pointer">
                ⟳ مزامنة الآن
            </button>
            <button type="button" id="subs-ont-start"
                    style="display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;border:1px solid #0f766e;background:transparent;color:#0f766e;font:inherit;font-weight:700;font-size:15px;cursor:pointer">
                📶 قراءة القدرة المستلمة
            </button>
            <span id="subs-sync-status" style="font-size:14px;font-weight:600"></span>
        </div>
        <div id="subs-auto-box" style="margin:14px 0 10px;padding:12px 14px;border:1px dashed var(--subs-border);border-radius:12px">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
                <b style="font-size:14px">🔁 المزامنة التلقائية</b>
                <label style="font-size:14px">كل
                    <select id="subs-auto-minutes" style="padding:4px 8px;border-radius:8px;border:1px solid var(--subs-border);background:transparent;color:inherit;font:inherit">
                        <option value="15">15 دقيقة</option>
                        <option value="30" selected>30 دقيقة</option>
                        <option value="60">ساعة</option>
                        <option value="120">ساعتين</option>
                    </select>
                </label>
                <button type="button" id="subs-auto-start"
                        style="padding:8px 16px;border-radius:10px;border:0;background:#0f766e;color:#fff;font:inherit;font-weight:700;cursor:pointer">تشغيل المزامنة التلقائية</button>
                <button type="button" id="subs-auto-stop"
                        style="padding:8px 16px;border-radius:10px;border:1px solid #b91c1c;background:transparent;color:#b91c1c;font:inherit;font-weight:700;cursor:pointer;display:none">إيقاف</button>
            </div>
            <div id="subs-auto-status" style="font-size:13.5px;font-weight:600;margin-top:6px"></div>
            <p style="font-size:13px;color:var(--subs-muted);margin:6px 0 0;line-height:1.8">
                تزامن الاشتراكات ثم تقرأ القدرة المستلمة للأجهزة اللي ما انقرت مؤخراً، كل فترة، ما دام هذا التبويب وتبويب موقع الشركة مفتوحين
                (مناسبة لجهاز أو RDP يبقى شغّال). تستخدم جلستك أنت وطلبات قراءة فقط؛ إذا انتهت جلسة موقع الشركة تنتظر حتى تسجّل الدخول بنفسك ثم تكمل، ويوصل تنبيه واتساب لأرقام التنبيهات.
                سجّل دخولك للنظام على هذا الجهاز مع «تذكرني» حتى لا تنقطع جلسة النظام.
            </p>
        </div>
        <p style="font-size:13px;color:var(--subs-muted);margin:0">
            يفتح تبويب كل الاشتراكات (الفعّالة والمنتهية) في موقع الشركة. إذا لم تكن مسجّلاً يطلب الموقع تسجيل الدخول، ثم تبدأ المزامنة وحدها وتظهر النتيجة هنا.
            آخر مزامنة: <span dir="ltr">{{ $lastBrowser?->started_at->format('Y/m/d H:i') ?? '—' }}</span>
        </p>

        <details style="margin-top:16px;font-size:14px;line-height:2" {{ $lastBrowser ? '' : 'open' }}>
            <summary style="cursor:pointer;font-weight:700">تنصيب إضافة كروم (مرة واحدة)</summary>
            <ol style="margin:6px 0 0;padding-inline-start:20px">
                <li><a href="{{ route('sync.extension') }}" style="color:#0f766e;font-weight:700">نزّل ملف الإضافة</a> وفكّ الضغط عنه (كليك يمين ← استخراج الكل). يظهر مجلد اسمه <b dir="ltr">subs-sync</b>.</li>
                <li>في كروم افتح العنوان <b dir="ltr" style="user-select:all">chrome://extensions</b></li>
                <li>فعّل <b>وضع المطوّر</b> (Developer mode) من أعلى الصفحة.</li>
                <li>اضغط <b>تحميل الإضافة غير المضغوطة</b> (Load unpacked) واختر مجلد <b dir="ltr">subs-sync</b>.</li>
                <li>ارجع لهذه الصفحة واضغط <b>مزامنة الآن</b>.</li>
            </ol>
            <p style="font-size:13px;color:var(--subs-muted);margin:4px 0 0">إذا تغيّر عنوان النظام (الدومين) نزّل الإضافة من جديد واستبدلها.</p>
        </details>

        <details style="margin-top:8px;font-size:14px;line-height:2">
            <summary style="cursor:pointer;font-weight:700">بديل بدون إضافة: زر في شريط الإشارات</summary>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:6px">
                <a href="{{ $bookmarklet }}" onclick="event.preventDefault(); alert('اسحب هذا الزر إلى شريط الإشارات، ثم اضغطه وأنت في صفحة الاشتراكات في موقع الشركة.')"
                   style="display:inline-flex;padding:8px 14px;border-radius:10px;background:#0f766e;color:#fff;font-weight:700;text-decoration:none;cursor:grab">⟳ مزامنة المشتركين</a>
                <span style="font-size:13px;color:var(--subs-muted)">اسحبه إلى شريط الإشارات (Ctrl+Shift+B لإظهاره)، وافتح admin.ftth.iq/subscriptions مسجّلاً ثم اضغطه.</span>
            </div>
        </details>
    </x-filament::section>

    @include('sync.receiver-script')
    <script>
    (() => {
        if (window.__subsSyncPage) return;
        window.__subsSyncPage = true;
        const set = (text, cls) => {
            const el = document.getElementById('subs-sync-status');
            if (!el) return;
            el.textContent = text;
            el.style.color = cls === 'ok' ? 'var(--subs-green)' : (cls === 'bad' ? 'var(--subs-red)' : '');
        };
        let heard = false, watchdog = null;
        // «المزامنة التلقائية»: remembered in this tab, so a reload keeps showing it (the panel tab keeps running).
        const AUTO = 'subs_auto_on';
        const autoOn = () => { try { return !!sessionStorage.getItem(AUTO); } catch (e) { return false; } };
        const autoShow = (text) => {
            const on = autoOn();
            const st = document.getElementById('subs-auto-status');
            if (st && text !== undefined) st.textContent = text;
            const a = document.getElementById('subs-auto-start'), b = document.getElementById('subs-auto-stop');
            if (a) a.style.display = on ? 'none' : '';
            if (b) b.style.display = on ? '' : 'none';
        };
        document.addEventListener('DOMContentLoaded', () => autoShow(autoOn() ? 'شغّالة. لا تسكّر هذا التبويب ولا تبويب موقع الشركة.' : ''));
        setTimeout(() => autoShow(autoOn() ? 'شغّالة. لا تسكّر هذا التبويب ولا تبويب موقع الشركة.' : undefined), 0);
        window.subsReceiver({
            onHello: () => { heard = true; set('فُتح موقع الشركة. سجّل الدخول إذا طُلب منك، وتبدأ المزامنة وحدها…', ''); },
            onStatus: (text, cls) => { heard = true; set(text, cls); },
            onAuto: (text) => { heard = true; autoShow(text); },
            // While the automatic sync runs, the page stays (a reload would cut the readings that follow).
            onDone: () => { if (!autoOn()) setTimeout(() => location.reload(), 4000); },
        });
        document.addEventListener('click', (e) => {
            const start = e.target.closest && e.target.closest('#subs-auto-start');
            const stop = e.target.closest && e.target.closest('#subs-auto-stop');
            if (start) {
                const minutes = document.getElementById('subs-auto-minutes')?.value || '30';
                const tab = window.open('https://admin.ftth.iq/subscriptions#subs-auto-' + minutes, 'ftth_auto');
                if (!tab) { autoShow('اسمح بالنوافذ المنبثقة لهذا النظام ثم اضغط مرة ثانية.'); return; }
                try { sessionStorage.setItem(AUTO, minutes); } catch (err) {}
                heard = false;
                autoShow('جارٍ فتح موقع الشركة… سجّل الدخول إذا طُلب منك.');
                clearTimeout(watchdog);
                watchdog = setTimeout(() => { if (!heard) autoShow('لم تستجب الإضافة. نزّل الإضافة الجديدة (1.5.0) وبدّلها، ثم أعد المحاولة.'); }, 30000);
            }
            if (stop) {
                // The panel tab by its name; a blank tab means it was already closed.
                const tab = window.open('', 'ftth_auto');
                try {
                    if (tab && tab.location.href === 'about:blank') tab.close();
                } catch (err) {
                    tab.postMessage({ type: 'auto_stop' }, 'https://admin.ftth.iq');
                }
                try { sessionStorage.removeItem(AUTO); } catch (err) {}
                autoShow('أُوقفت المزامنة التلقائية.');
            }
        });
        document.addEventListener('click', (e) => {
            const button = e.target.closest && e.target.closest('#subs-sync-start, #subs-ont-start, #subs-import-start');
            if (!button) return;
            heard = false;
            // The readings have their own button and run, so they never slow the activations sync.
            const mode = button.id === 'subs-ont-start' ? 'subs-ont' : 'subs-sync';
            // Every sync reads the whole list (active and ended), so no subscriber is left out.
            const list = 'https://admin.ftth.iq/subscriptions';
            const tab = window.open(list + '#' + mode, 'ftth_sync');
            if (!tab) { set('اسمح بالنوافذ المنبثقة لهذا النظام ثم اضغط مرة ثانية.', 'bad'); return; }
            set('جارٍ فتح موقع الشركة…', '');
            clearTimeout(watchdog);
            watchdog = setTimeout(() => {
                if (!heard) set('لم تستجب إضافة المزامنة. تأكد أنها منصّبة (الخطوات بالأسفل)، ثم أعد المحاولة.', 'bad');
            }, 25000);
        });
    })();
    </script>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">المزامنة التلقائية</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $enabled ? 'var(--subs-green)' : 'var(--subs-muted)' }}">{{ $enabled ? "كل {$interval} دقيقة" : 'متوقفة' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">آخر مزامنة ناجحة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px" dir="ltr">{{ $last?->started_at->format('Y/m/d H:i') ?? '—' }}</div>
            @if ($last)
                <div style="font-size:13px;color:var(--subs-muted)">{{ $last->stats['received'] ?? 0 }} سجل · {{ $last->stats['renewals'] ?? 0 }} تجديد</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">جلسة موقع الشركة</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px;color:{{ $hasRefreshToken ? 'var(--subs-green)' : 'var(--subs-muted)' }}">{{ $hasRefreshToken ? 'متصلة' : 'غير متصلة' }}</div>
            <div style="font-size:13px;color:var(--subs-muted)" dir="ltr">{{ $refreshedAt ? 'آخر تجديد '.$refreshedAt->format('Y/m/d H:i') : '' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;color:var(--subs-muted);font-weight:600">قاعدة التجديد</div>
            <div style="font-size:13.5px;margin-top:4px;line-height:1.7">آخر أيام = 0 ثم: 7 أيام أو أقل ← دين ثانوي بسعر الفئة · أكثر من 7 أيام ← تفعيل بدون دين. مرة واحدة لكل تجديد.</div>
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
            <p style="color:var(--subs-muted);font-size:14px">لا توجد تجديدات بعد.</p>
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
                                <td style="{{ $td }}">{{ $r->previous_days }} ← {{ $r->new_days }}@if ($r->payment_source)<div style="font-size:12px;font-weight:700;color:{{ $r->payment_source === 'wallet' ? 'rgb(37 99 235)' : 'rgb(217 119 6)' }}">{{ \App\Models\AccountRenewal::PAYMENT_SOURCES[$r->payment_source] }}</div>@endif</td>
                                <td style="{{ $td }}">{{ $r->plan_name ?? '—' }}</td>
                                <td style="{{ $td }}">
                                    @if ($r->status === 'activated')
                                        <x-filament::badge color="success">تفعيل كامل – بدون دين</x-filament::badge>
                                        @if ($r->decision === 'debt')<div style="font-size:12px;margin-top:3px">المفعّل حديثاً: دين أولي</div>@elseif ($r->decision === 'transfer')<div style="font-size:12px;margin-top:3px">المفعّل حديثاً: مناقلة</div>@elseif ($r->decision === 'cash')<div style="font-size:12px;margin-top:3px">المفعّل حديثاً: نقد</div>@endif
                                    @elseif ($r->status === 'test')
                                        <x-filament::badge color="gray">تيست من المشترك – بدون دين</x-filament::badge>
                                    @elseif ($r->debt)
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
            <p style="color:var(--subs-muted);font-size:14px">لم تُنفَّذ أي مزامنة بعد.</p>
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
                                    @if ($run->error)<div style="color:var(--subs-red);font-size:12px;margin-top:4px">{{ $run->error }}</div>@endif
                                </td>
                                <td style="{{ $td }}">{{ $s['received'] ?? 0 }}</td>
                                <td style="{{ $td }}">{{ ($s['subscribers_created'] ?? 0) }} مشترك · {{ ($s['accounts_created'] ?? 0) }} حساب</td>
                                <td style="{{ $td }}">{{ $s['accounts_updated'] ?? 0 }}</td>
                                <td style="{{ $td }}">{{ $s['renewals'] ?? 0 }} / {{ $s['debts_created'] ?? 0 }}@if ($s['self_tests'] ?? 0)<div style="font-size:12px;color:var(--subs-muted)">{{ $s['self_tests'] }} تيست بدون دين</div>@endif</td>
                                <td style="{{ $td }}">
                                    @php $errors = $s['errors'] ?? []; @endphp
                                    @if ($errors)
                                        <details><summary style="cursor:pointer;color:var(--subs-red)">{{ count($errors) }}</summary>
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

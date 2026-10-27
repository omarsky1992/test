{{-- Receives what the browser sync (extension or bookmark) read on the company panel and posts it to the system. --}}
<script>
window.subsReceiver = function ({ onStatus, onDone, onHello }) {
    const PANEL = {!! json_encode(\App\Http\Controllers\BrowserSyncController::PANEL_ORIGIN, JSON_UNESCAPED_SLASHES) !!};
    const csrf = document.querySelector('meta[name=csrf-token]')?.content;
    window.addEventListener('message', async (event) => {
        if (event.origin !== PANEL || !event.data) return;
        const data = event.data;
        if (data.type === 'hello') { onHello && onHello(); return; }
        if (data.type === 'progress') { onStatus(data.text, ''); return; }
        if (data.type === 'ont_done') { onStatus((data.ok ? 'تمت ✓ ' : '') + data.text, data.ok ? 'ok' : 'bad'); return; }
        if (!['plan', 'run', 'ont_plan', 'ont_run'].includes(data.type)) return;
        // «القدرة المستلمة» is its own run with its own two steps.
        const ontRun = data.type.startsWith('ont_');
        const url = { plan: 'plan', run: 'run', ont_plan: 'ont-plan', ont_run: 'ont' }[data.type];
        onStatus(data.type === 'plan' ? `وصلت ${data.subscriptions.length} اشتراك. جارٍ التحقق…` : (data.type === 'ont_plan' ? 'جارٍ تحديد الأجهزة…' : 'جارٍ الحفظ في النظام…'), '');
        try {
            const response = await fetch(`/sync/browser/${url}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(ontRun ? { ont: data.ont || {} } : { customers: data.customers, subscriptions: data.subscriptions, details: data.details || {}, wallet: data.wallet || null }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(body.summary || body.message || `رمز ${response.status}`);
            if (data.type === 'plan') {
                onStatus(body.needs.length ? `جلب تفاصيل ${body.needs.length} مشترك من الموقع…` : 'جارٍ الحفظ…', '');
            } else if (data.type === 'ont_plan') {
                onStatus(`قراءة القدرة المستلمة لـ ${body.ont.length} جهاز…`, '');
            } else if (data.type === 'ont_run') {
                // One batch saved; the run goes on and ends with ont_done.
            } else {
                onStatus('تمت المزامنة ✓ ' + body.summary, 'ok');
                onDone && onDone(body);
            }
            event.source.postMessage({ type: `${data.type}_ok`, ...body }, PANEL);
        } catch (error) {
            onStatus('فشلت المزامنة: ' + error.message, 'bad');
            event.source.postMessage({ type: 'error', message: error.message }, PANEL);
        }
    });
};
</script>

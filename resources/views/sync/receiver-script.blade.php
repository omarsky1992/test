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
        if (!['plan', 'run'].includes(data.type)) return;
        onStatus(data.type === 'plan' ? `وصلت ${data.subscriptions.length} اشتراك. جارٍ التحقق…` : 'جارٍ الحفظ في النظام…', '');
        try {
            const response = await fetch(`/sync/browser/${data.type}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ customers: data.customers, subscriptions: data.subscriptions, details: data.details || {} }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(body.summary || body.message || `رمز ${response.status}`);
            if (data.type === 'plan') {
                onStatus(body.needs.length ? `جلب تفاصيل ${body.needs.length} مشترك من الموقع…` : 'جارٍ الحفظ…', '');
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

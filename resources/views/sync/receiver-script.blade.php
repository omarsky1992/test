{{-- Receives what the browser sync (extension or bookmark) read on the company panel and posts it to the system. --}}
<script>
window.subsReceiver = function ({ onStatus, onDone, onHello, onAuto }) {
    const PANEL = {!! json_encode(\App\Http\Controllers\BrowserSyncController::PANEL_ORIGIN, JSON_UNESCAPED_SLASHES) !!};
    let csrf = document.querySelector('meta[name=csrf-token]')?.content;
    // A page left open for hours (the automatic sync): when the session ran out and «تذكرني» signed the
    // user in again, the old form token is refused (419). Take a fresh one and try once more.
    const post = async (url, payload) => {
        const send = () => fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload),
        });
        let response = await send();
        if (response.status === 419 || response.status === 401) {
            const fresh = await fetch('/sync/browser/token', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).catch(() => null);
            if (fresh && fresh.ok) {
                csrf = (await fresh.json()).token;
                response = await send();
            } else {
                throw new Error('انتهت جلستك في النظام. سجّل الدخول للنظام مع «تذكرني» ثم أعد التشغيل.');
            }
        }
        return response;
    };
    window.addEventListener('message', async (event) => {
        if (event.origin !== PANEL || !event.data) return;
        const data = event.data;
        if (data.type === 'hello') { onHello && onHello(); return; }
        if (data.type === 'progress') { onStatus(data.text, ''); return; }
        if (data.type === 'auto_state') { onAuto ? onAuto(data.text) : onStatus(data.text, ''); return; }
        if (data.type === 'ont_done') { onStatus((data.ok ? 'تمت ✓ ' : '') + data.text, data.ok ? 'ok' : 'bad'); return; }
        if (data.type === 'session_lost') { post('/sync/browser/session-lost', {}).catch(() => {}); return; }
        if (!['plan', 'run', 'ont_plan', 'ont_run'].includes(data.type)) return;
        // «القدرة المستلمة» is its own run with its own two steps.
        const ontRun = data.type.startsWith('ont_');
        const url = { plan: 'plan', run: 'run', ont_plan: 'ont-plan', ont_run: 'ont' }[data.type];
        onStatus(data.type === 'plan' ? `وصلت ${data.subscriptions.length} اشتراك. جارٍ التحقق…` : (data.type === 'ont_plan' ? 'جارٍ تحديد الأجهزة…' : 'جارٍ الحفظ في النظام…'), '');
        try {
            const response = await post(`/sync/browser/${url}`, ontRun ? { ont: data.ont || {} } : { customers: data.customers, subscriptions: data.subscriptions, details: data.details || {}, wallet: data.wallet || null });
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

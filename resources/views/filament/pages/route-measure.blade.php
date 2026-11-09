{{-- «قياس المسار»: a client-side cable-length tool. Leaflet (self-hosted) + the phone's GPS.
     Accuracy: a GPS point is not the phone's first (often coarse) fix but the average of many readings
     weighted by their accuracy, shown as ±metres; the route can follow the real cable path (points
     added along it, a walked track, or a point inserted on any segment); distances use the WGS84
     ellipsoid. It reads and writes nothing in the system. --}}
<x-filament-panels::page>
    <div x-data="routeMeasure()" x-init="init()" style="display:flex;flex-direction:column;gap:16px" dir="rtl">

        <x-filament::section>
            <p style="font-size:14px;line-height:1.9;margin:0 0 12px">
                ثلاث طرق للقياس، وتكدر تخلطهن بنفس المسار:
                <b>① نقطة دقيقة</b> عند العمود وعند البيت وعند كل لفّة بالطريق ·
                <b>② تتبّع مشياً</b> على طريق الكيبل من العمود للبيت ·
                <b>③ على الخريطة</b>: قرّب كلش واضغط، واضغط على الخط نفسه لتضيف نقطة لفّة بالنص، واسحب أي نقطة لتصحيحها.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <button type="button" @click="precisePoint()" x-show="!fixing && !tracking"
                        style="padding:11px 18px;border:0;border-radius:10px;background:#0f766e;color:#fff;font:inherit;font-weight:700;font-size:15px;cursor:pointer">📍 نقطة دقيقة من موقعي</button>
                <button type="button" @click="startTrack()" x-show="!fixing && !tracking"
                        style="padding:11px 18px;border:1px solid #0f766e;border-radius:10px;background:transparent;color:#0f766e;font:inherit;font-weight:700;font-size:15px;cursor:pointer">🚶 تتبّع المسار مشياً</button>
                <button type="button" @click="undo()" x-show="points.length && !fixing && !tracking"
                        style="padding:10px 16px;border:1px solid var(--subs-border);border-radius:10px;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer">↩ تراجع</button>
                <button type="button" @click="clearPoints()" x-show="points.length && !fixing && !tracking"
                        style="padding:10px 16px;border:1px solid #b91c1c;border-radius:10px;background:transparent;color:#b91c1c;font:inherit;font-weight:600;cursor:pointer">🗑 مسح النقاط</button>
            </div>

            {{-- While a precise point is being fixed: live accuracy, and «ثبّت الآن» if the technician cannot wait. --}}
            <div x-show="fixing" x-cloak style="margin-top:12px;padding:12px;border-radius:10px;background:var(--subs-subtle, #f1f5f9)">
                <div style="font-weight:700">⏳ جارٍ تحديد النقطة بدقة… لا تتحرك</div>
                <div style="font-size:14px;margin-top:4px">
                    الدقة الآن: <b dir="ltr" x-text="liveAcc === null ? '—' : '±' + liveAcc.toFixed(1) + ' م'"></b>
                    · قراءات جيدة: <b x-text="goodCount"></b>
                    · الهدف: <b dir="ltr">±<span x-text="target"></span> م</b>
                </div>
                <div style="display:flex;gap:10px;margin-top:10px">
                    <button type="button" @click="finishFix(true)" :disabled="!samples.length"
                            style="padding:8px 14px;border:0;border-radius:8px;background:#0f766e;color:#fff;font:inherit;font-weight:700;cursor:pointer">ثبّت النقطة الآن</button>
                    <button type="button" @click="cancelFix()"
                            style="padding:8px 14px;border:1px solid var(--subs-border);border-radius:8px;background:transparent;color:inherit;font:inherit;cursor:pointer">إلغاء</button>
                </div>
            </div>

            {{-- While walking the cable path. --}}
            <div x-show="tracking" x-cloak style="margin-top:12px;padding:12px;border-radius:10px;background:var(--subs-subtle, #f1f5f9)">
                <div style="font-weight:700">🚶 التتبّع شغّال: امشِ على طريق الكيبل بهدوء</div>
                <div style="font-size:14px;margin-top:4px">
                    الدقة الآن: <b dir="ltr" x-text="liveAcc === null ? '—' : '±' + liveAcc.toFixed(1) + ' م'"></b>
                    · نقاط مسجّلة: <b x-text="trackAdded"></b>
                    · الطول: <b dir="ltr"><span x-text="fmt(totalMeters)"></span> م</b>
                </div>
                <button type="button" @click="stopTrack()"
                        style="margin-top:10px;padding:8px 16px;border:0;border-radius:8px;background:#b91c1c;color:#fff;font:inherit;font-weight:700;cursor:pointer">⏹ إيقاف (وصلت البيت)</button>
            </div>

            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:12px;font-size:13.5px">
                <label>الدقة المطلوبة للنقطة:
                    <select x-model.number="target" style="padding:3px 8px;border-radius:8px;border:1px solid var(--subs-border);background:transparent;color:inherit;font:inherit">
                        <option value="3">±3 م (مكان مفتوح)</option>
                        <option value="5">±5 م</option>
                        <option value="8">±8 م</option>
                    </select>
                </label>
            </div>
            <p x-show="message" x-text="message" :style="messageKind === 'error' ? 'color:var(--subs-red)' : 'color:var(--subs-muted)'" style="font-size:13px;font-weight:600;margin:10px 0 0"></p>
        </x-filament::section>

        {{-- The current route. --}}
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:10px">
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--subs-muted)">طول المسار</div>
                    <div style="font-size:26px;font-weight:800;color:#0f766e" dir="ltr"><span x-text="fmt(totalMeters)"></span> <span style="font-size:15px">م</span></div>
                    <div x-show="errorEstimate !== null" style="font-size:12.5px;color:var(--subs-muted)" dir="rtl">
                        هامش الخطأ المتوقع تقريباً: <span dir="ltr" x-text="'±' + (errorEstimate ?? 0).toFixed(1) + ' م'"></span>
                    </div>
                </div>
                <div style="text-align:left">
                    <label style="font-size:13px;color:var(--subs-muted)">احتياط (صعود العمود + دخول البيت):
                        <input type="number" min="0" step="1" x-model.number="slack" style="width:70px;padding:3px 6px;border-radius:8px;border:1px solid var(--subs-border);background:transparent;color:inherit;font:inherit"> م
                    </label>
                    <div style="font-size:15px;font-weight:800;margin-top:4px">طول الكيبل المطلوب: <span dir="ltr" style="color:#15803d"><span x-text="needed"></span> م</span></div>
                </div>
            </div>

            <div x-ref="map" wire:ignore style="height:380px;border-radius:12px;margin-top:12px;background:#e5e7eb;z-index:0"></div>
            <p style="font-size:12.5px;color:var(--subs-muted);margin:6px 0 0">
                الإضافة بالضغط على الخريطة تشتغل لما تقرّب كفاية (مستوى 17 أو أكثر) حتى تكون النقطة دقيقة. الدائرة حول كل نقطة GPS = هامش دقتها.
            </p>

            <template x-if="points.length">
                <div style="margin-top:12px;overflow-x:auto">
                    <table style="width:100%;font-size:13.5px;border-collapse:collapse;min-width:520px" dir="rtl">
                        <thead><tr>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">#</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">خط العرض (Lat)</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">خط الطول (Lng)</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">المصدر / الدقة</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">من السابقة</th>
                            <th style="padding:6px"></th>
                        </tr></thead>
                        <tbody>
                            <template x-for="(p, i) in points" :key="i">
                                <tr style="border-top:1px solid var(--subs-subtle)">
                                    <td style="padding:6px;font-weight:700" x-text="i + 1"></td>
                                    <td style="padding:6px" dir="ltr" x-text="p.lat.toFixed(7)"></td>
                                    <td style="padding:6px" dir="ltr" x-text="p.lng.toFixed(7)"></td>
                                    <td style="padding:6px" :style="p.acc !== null && p.acc > 10 ? 'color:var(--subs-red);font-weight:700' : ''"
                                        x-text="sourceLabel(p)"></td>
                                    <td style="padding:6px" dir="ltr" x-text="i === 0 ? '—' : fmt(segMeters(i)) + ' م'"></td>
                                    <td style="padding:6px;white-space:nowrap">
                                        <button type="button" @click="copy(p.lat.toFixed(7) + ', ' + p.lng.toFixed(7))"
                                                style="padding:4px 8px;border:1px solid var(--subs-border);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12px;cursor:pointer">نسخ</button>
                                        <button type="button" @click="removePoint(i)"
                                                style="padding:4px 8px;border:1px solid #b91c1c;border-radius:8px;background:transparent;color:#b91c1c;font:inherit;font-size:12px;cursor:pointer">✕</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px">
                        <button type="button" @click="copyAll()"
                                style="padding:9px 16px;border:1px solid var(--subs-border);border-radius:10px;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer">📋 نسخ كل الإحداثيات</button>
                        <button type="button" @click="saveRoute()" x-show="points.length >= 2 && !tracking"
                                style="padding:9px 18px;border:0;border-radius:10px;background:#15803d;color:#fff;font:inherit;font-weight:700;cursor:pointer">💾 احفظ هذا المسار للمقارنة</button>
                    </div>
                </div>
            </template>
        </x-filament::section>

        {{-- Saved routes, to compare and pick the shortest. --}}
        <x-filament::section x-show="routes.length" style="display:none" x-cloak>
            <div style="font-size:14px;font-weight:700;margin-bottom:10px">المسارات المحفوظة (للمقارنة)</div>
            <table style="width:100%;font-size:14px;border-collapse:collapse" dir="rtl">
                <thead><tr>
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">المسار</th>
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">النقاط</th>
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">الطول</th>
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">مع الاحتياط</th>
                    <th style="padding:6px"></th>
                </tr></thead>
                <tbody>
                    <template x-for="r in routes" :key="r.id">
                        <tr style="border-top:1px solid var(--subs-subtle)" :style="r.meters === shortest ? 'background:#ecfdf5' : ''">
                            <td style="padding:8px 6px;font-weight:700">
                                <span x-text="r.name"></span>
                                <span x-show="r.meters === shortest" style="color:var(--subs-green);font-size:12px;font-weight:700"> ✓ الأقصر</span>
                            </td>
                            <td style="padding:8px 6px" x-text="r.points.length"></td>
                            <td style="padding:8px 6px;font-weight:700;color:#0f766e" dir="ltr"><span x-text="fmt(r.meters)"></span> م</td>
                            <td style="padding:8px 6px" dir="ltr"><span x-text="Math.ceil(r.meters + (r.slack || 0))"></span> م</td>
                            <td style="padding:8px 6px;white-space:nowrap">
                                <button type="button" @click="showRoute(r)"
                                        style="padding:4px 10px;border:1px solid var(--subs-border);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12px;cursor:pointer">عرض</button>
                                <button type="button" @click="removeRoute(r.id)"
                                        style="padding:4px 10px;border:1px solid #b91c1c;border-radius:8px;background:transparent;color:#b91c1c;font:inherit;font-size:12px;cursor:pointer">حذف</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p style="font-size:12.5px;color:var(--subs-muted);margin:10px 0 0">المسارات محفوظة على هذا الجهاز فقط (هذا المتصفح).</p>
        </x-filament::section>
    </div>

    <script>
        // Distance in metres between two points on the WGS84 ellipsoid (local radii of curvature at the
        // mean latitude). For cable runs (metres to a few km) it matches geodesic tools to millimetres,
        // unlike a spherical formula, which can be off by ~0.5%.
        window.subsGeoDistance = function (a, b) {
            const A = 6378137, E2 = 0.00669437999014, rad = Math.PI / 180;
            const phi = ((a.lat + b.lat) / 2) * rad, s = Math.sin(phi), w = 1 - E2 * s * s;
            const M = A * (1 - E2) / Math.pow(w, 1.5), N = A / Math.sqrt(w);
            const dy = (b.lat - a.lat) * rad * M, dx = (b.lng - a.lng) * rad * N * Math.cos(phi);
            return Math.sqrt(dx * dx + dy * dy);
        };

        // The best position from many GPS readings: drop the coarse ones, weight the rest by 1/accuracy².
        window.subsFusePosition = function (samples) {
            if (!samples.length) return null;
            const best = Math.min(...samples.map(s => s.acc));
            const keep = samples.filter(s => s.acc <= Math.max(best * 1.5, best + 2));
            let wl = 0, sLat = 0, sLng = 0;
            keep.forEach(s => { const w = 1 / (s.acc * s.acc); wl += w; sLat += s.lat * w; sLng += s.lng * w; });
            // Readings a few seconds apart share most of their error, so the reported accuracy stays the best single one.
            return { lat: sLat / wl, lng: sLng / wl, acc: best, n: keep.length };
        };

        window.routeMeasure = window.routeMeasure || function () {
            return {
                points: [], routes: [], map: null, line: null, layers: [],
                fixing: false, tracking: false, watchId: null, samples: [], liveAcc: null, goodCount: 0, fixTimer: null,
                trackAdded: 0, target: 5, slack: 0, message: '', messageKind: 'info',

                init() {
                    try { this.routes = JSON.parse(localStorage.getItem('subs_routes') || '[]') || []; } catch (e) { this.routes = []; }
                    try { this.slack = Number(localStorage.getItem('subs_route_slack') || 0) || 0; } catch (e) {}
                    this.$watch('slack', (v) => { try { localStorage.setItem('subs_route_slack', String(v || 0)); } catch (e) {} });
                    this.loadLeaflet().then(() => this.$nextTick(() => this.createMap()))
                        .catch(() => this.say('تعذّر تحميل الخريطة. تحقق من الإنترنت. «نقطة دقيقة» و«التتبّع» يشتغلون بدونها.', 'error'));
                },

                loadLeaflet() {
                    if (window.L) return Promise.resolve();
                    window.__leafletLoading = window.__leafletLoading || new Promise((resolve, reject) => {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet'; css.href = @js(asset('vendor/leaflet/leaflet.css'));
                        document.head.appendChild(css);
                        const js = document.createElement('script');
                        js.src = @js(asset('vendor/leaflet/leaflet.js'));
                        js.onload = resolve;
                        js.onerror = () => { window.__leafletLoading = null; reject(); };
                        document.head.appendChild(js);
                    });
                    return window.__leafletLoading;
                },

                createMap() {
                    const L = window.L;
                    this.map = L.map(this.$refs.map, { zoomControl: true, maxZoom: 22 }).setView([33.3152, 44.3661], 6);
                    // Zoom past the tiles' own detail (19) so a point can be placed to the centimetre.
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap',
                    }).addTo(this.map);
                    this.map.on('click', (e) => {
                        if (this.map.getZoom() < 17) { this.say('قرّب الخريطة أكثر (مستوى 17 أو أكثر) حتى تكون النقطة دقيقة، ثم اضغط.', 'error'); return; }
                        this.addPoint({ lat: e.latlng.lat, lng: e.latlng.lng, acc: null, src: 'map' });
                    });
                    setTimeout(() => this.map.invalidateSize(), 200);
                    // Open on where the technician is (a rough fix is enough to centre the map).
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition((p) => { if (!this.points.length) this.map.setView([p.coords.latitude, p.coords.longitude], 18); }, () => {}, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
                    }
                },

                dist(a, b) { return window.subsGeoDistance(a, b); },
                lengthOf(pts) { let m = 0; for (let i = 1; i < pts.length; i++) m += this.dist(pts[i - 1], pts[i]); return m; },
                get totalMeters() { return this.lengthOf(this.points); },
                get needed() { return this.points.length >= 2 ? Math.ceil(this.totalMeters + (Number(this.slack) || 0)) : 0; },
                // The ends decide the error; points in between only bend the path.
                get errorEstimate() {
                    if (this.points.length < 2) return null;
                    const a = this.points[0].acc, b = this.points[this.points.length - 1].acc;
                    if (a === null && b === null) return null;
                    return Math.sqrt((a || 0) ** 2 + (b || 0) ** 2);
                },
                segMeters(i) { return i === 0 ? 0 : this.dist(this.points[i - 1], this.points[i]); },
                get shortest() { return this.routes.length ? Math.min(...this.routes.map(r => r.meters)) : null; },
                fmt(m) { return (Math.round(m * 10) / 10).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); },
                sourceLabel(p) {
                    if (p.src === 'map') return 'خريطة';
                    const acc = p.acc === null ? '' : ' ±' + p.acc.toFixed(1) + ' م';
                    return (p.src === 'track' ? 'تتبّع' : 'GPS' + (p.n ? ' (' + p.n + ' قراءة)' : '')) + acc;
                },

                addPoint(p, at = null) {
                    if (!isFinite(p.lat) || !isFinite(p.lng)) return;
                    if (at === null) this.points.push(p); else this.points.splice(at, 0, p);
                    this.redraw(at === null && this.points.length <= 2);
                },
                removePoint(i) { this.points.splice(i, 1); this.redraw(false); },
                undo() { this.points.pop(); this.redraw(false); },
                clearPoints() { this.points = []; this.redraw(false); },

                // ① A precise point: many readings, fused; ends when the target accuracy holds or the technician fixes it.
                precisePoint() {
                    if (!navigator.geolocation) { this.say('جهازك لا يدعم تحديد الموقع.', 'error'); return; }
                    this.fixing = true; this.samples = []; this.liveAcc = null; this.goodCount = 0; this.say('');
                    let streak = 0;
                    this.watchId = navigator.geolocation.watchPosition((pos) => {
                        const s = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy };
                        this.liveAcc = s.acc;
                        if (s.acc > 50) return; // network/cell fixes: never used
                        this.samples.push(s);
                        if (s.acc <= this.target) { this.goodCount++; streak++; } else { streak = 0; }
                        // Enough steady good readings: the point is fixed.
                        if (streak >= 5) this.finishFix(false);
                    }, (err) => {
                        this.cancelFix();
                        this.say(err.code === 1 ? 'لم يُسمح بالوصول إلى موقعك. فعّل إذن الموقع للمتصفح.' : 'تعذّر تحديد موقعك. اطلع لمكان مفتوح وحاول.', 'error');
                    }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 });
                    // Never more than 45 seconds: then the best of what came.
                    this.fixTimer = setTimeout(() => { if (this.fixing) this.finishFix(true); }, 45000);
                },
                finishFix(manual) {
                    const fused = window.subsFusePosition(this.samples);
                    this.stopWatch();
                    this.fixing = false;
                    if (!fused) { this.say('ما وصلت قراءة دقيقة. اطلع لمكان مفتوح بعيد عن الجدران وحاول.', 'error'); return; }
                    this.addPoint({ lat: fused.lat, lng: fused.lng, acc: fused.acc, n: fused.n, src: 'gps' });
                    if (this.points.length === 1 && this.map) this.map.setView([fused.lat, fused.lng], 19);
                    this.say(fused.acc > 10
                        ? 'أُضيفت النقطة لكن دقتها ضعيفة (±' + fused.acc.toFixed(1) + ' م). الأفضل تعيدها بمكان مفتوح أو تصححها بسحبها على الخريطة.'
                        : 'أُضيفت نقطة بدقة ±' + fused.acc.toFixed(1) + ' م من ' + fused.n + ' قراءة.', fused.acc > 10 ? 'error' : 'info');
                },
                cancelFix() { this.stopWatch(); this.fixing = false; },
                stopWatch() {
                    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
                    this.watchId = null;
                    clearTimeout(this.fixTimer);
                },

                // ② Walking the cable path: a point each time the walker has clearly moved, from good readings only.
                startTrack() {
                    if (!navigator.geolocation) { this.say('جهازك لا يدعم تحديد الموقع.', 'error'); return; }
                    this.tracking = true; this.trackAdded = 0; this.liveAcc = null; this.say('');
                    let window5 = [];
                    this.watchId = navigator.geolocation.watchPosition((pos) => {
                        const s = { lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy };
                        this.liveAcc = s.acc;
                        if (s.acc > 12) return;
                        // Smooth over the last few readings so standing still does not add jitter.
                        window5.push(s); if (window5.length > 5) window5.shift();
                        const here = window.subsFusePosition(window5);
                        const last = this.points[this.points.length - 1];
                        const step = Math.max(3, here.acc * 1.5);
                        if (!last || this.dist(last, here) >= step) {
                            this.addPoint({ lat: here.lat, lng: here.lng, acc: here.acc, src: 'track' });
                            this.trackAdded++;
                            if (this.map) this.map.panTo([here.lat, here.lng]);
                        }
                    }, (err) => {
                        this.stopTrack();
                        this.say(err.code === 1 ? 'لم يُسمح بالوصول إلى موقعك.' : 'انقطع تحديد الموقع.', 'error');
                    }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 });
                },
                stopTrack() {
                    this.stopWatch(); this.tracking = false;
                    this.say(this.trackAdded ? 'سُجّل المسار المشي (' + this.trackAdded + ' نقطة). يفضّل تضيف «نقطة دقيقة» عند البيت لنهاية أدق.' : 'ما تسجّلت نقاط: الدقة كانت ضعيفة أو ما تحركت كفاية.');
                },

                redraw(fit) {
                    const L = window.L;
                    if (!this.map || !L) return;
                    this.layers.forEach(l => this.map.removeLayer(l));
                    this.layers = [];
                    if (this.line) { this.map.removeLayer(this.line); this.line = null; }
                    const latlngs = this.points.map(p => [p.lat, p.lng]);
                    if (latlngs.length >= 2) {
                        this.line = L.polyline(latlngs, { color: '#0f766e', weight: 6 }).addTo(this.map);
                        // ③ A click on the line inserts a corner point there, between its two neighbours.
                        this.line.on('click', (e) => {
                            L.DomEvent.stopPropagation(e);
                            this.addPoint({ lat: e.latlng.lat, lng: e.latlng.lng, acc: null, src: 'map' }, this.segmentAt(e.latlng) + 1);
                        });
                    }
                    this.points.forEach((p, i) => {
                        if (p.acc) this.layers.push(L.circle([p.lat, p.lng], { radius: p.acc, color: '#0f766e', weight: 1, fillOpacity: 0.08 }).addTo(this.map));
                        const last = i === this.points.length - 1, first = i === 0;
                        const showLabel = first || last || p.src !== 'track';
                        const marker = L.marker([p.lat, p.lng], { draggable: true, opacity: p.src === 'track' && !first && !last ? 0.55 : 1 }).addTo(this.map);
                        if (showLabel) marker.bindTooltip(String(i + 1), { permanent: true, direction: 'top' });
                        marker.on('dragend', (e) => { const ll = e.target.getLatLng(); this.points[i] = { ...this.points[i], lat: ll.lat, lng: ll.lng, acc: null, src: 'map' }; this.redraw(false); });
                        this.layers.push(marker);
                    });
                    if (fit && latlngs.length >= 2) this.map.fitBounds(this.line.getBounds().pad(0.3), { maxZoom: 20 });
                },
                // The segment closest to a clicked point (index of its first end).
                segmentAt(ll) {
                    let best = 0, bestD = Infinity;
                    for (let i = 0; i < this.points.length - 1; i++) {
                        const a = this.points[i], b = this.points[i + 1];
                        const d = this.dist(a, ll) + this.dist(ll, b) - this.dist(a, b);
                        if (d < bestD) { bestD = d; best = i; }
                    }
                    return best;
                },

                showRoute(r) {
                    this.points = r.points.map(p => ({ acc: null, src: 'map', ...p }));
                    if (r.slack !== undefined) this.slack = r.slack;
                    this.redraw(true);
                    this.say('عُرض «' + r.name + '». تكدر تعدّله وتحفظه مساراً جديداً.');
                },
                saveRoute() {
                    if (this.points.length < 2) return;
                    const n = (this.routes.reduce((max, r) => Math.max(max, r.seq || 0), 0)) + 1;
                    this.routes.push({
                        id: (window.subsUuid ? subsUuid() : String(Date.now())), seq: n, name: 'مسار ' + n,
                        points: this.points.map(p => ({ lat: p.lat, lng: p.lng, acc: p.acc ?? null, src: p.src || 'map' })),
                        meters: Math.round(this.totalMeters * 10) / 10, slack: Number(this.slack) || 0,
                    });
                    this.persist();
                    this.clearPoints();
                    this.say('حُفظ المسار. تكدر تبدأ مساراً جديداً للمقارنة.');
                },
                removeRoute(id) { this.routes = this.routes.filter(r => r.id !== id); this.persist(); },
                persist() { try { localStorage.setItem('subs_routes', JSON.stringify(this.routes)); } catch (e) {} },

                copy(text) { this.copyText(text); this.say('نُسخ: ' + text); },
                copyAll() {
                    if (!this.points.length) return;
                    const lines = this.points.map((p, i) => (i + 1) + ') ' + p.lat.toFixed(7) + ', ' + p.lng.toFixed(7));
                    lines.push('الطول: ' + this.fmt(this.totalMeters) + ' م · المطلوب مع الاحتياط: ' + this.needed + ' م');
                    this.copyText(lines.join('\n'));
                    this.say('نُسخت كل الإحداثيات مع الطول.');
                },
                copyText(text) {
                    if (navigator.clipboard) { navigator.clipboard.writeText(text).catch(() => {}); return; }
                    const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
                    try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(t);
                },
                say(text, kind = 'info') { this.message = text; this.messageKind = kind; },
            };
        };
    </script>
</x-filament-panels::page>

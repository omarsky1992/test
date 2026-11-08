{{-- «قياس المسار»: a client-side cable-length tool. Leaflet (self-hosted) + the phone's GPS; it
     computes the route length in metres, keeps several routes to compare, and shows each point's
     latitude/longitude to send to the company. It reads and writes nothing in the system. --}}
<x-filament-panels::page>
    <div x-data="routeMeasure()" x-init="init()" style="display:flex;flex-direction:column;gap:16px" dir="rtl">

        <x-filament::section>
            <p style="font-size:14px;line-height:1.9;margin:0 0 12px">
                قف عند العمود واضغط <b>📍 نقطة من موقعي</b>، وامشِ إلى بيت المشترك واضغط الزر من جديد. يحسب طول المسار بالمتر لاختيار الكيبل المناسب.
                تكدر تضيف أكثر من نقطة على الطريق، وتحفظ أكثر من مسار وتقارن بينهم. كل نقطة يظهر خط طولها وعرضها لإرسالهن للشركة.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <button type="button" @click="gpsPoint()" :disabled="locating"
                        style="display:inline-flex;align-items:center;gap:6px;padding:11px 20px;border:0;border-radius:10px;background:#0f766e;color:#fff;font:inherit;font-weight:700;font-size:15px;cursor:pointer">
                    <span x-text="locating ? '⏳ جارٍ تحديد موقعك…' : '📍 نقطة من موقعي'"></span>
                </button>
                <button type="button" @click="undo()" x-show="points.length"
                        style="padding:10px 16px;border:1px solid var(--subs-border);border-radius:10px;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer">↩ تراجع</button>
                <button type="button" @click="clearPoints()" x-show="points.length"
                        style="padding:10px 16px;border:1px solid #b91c1c;border-radius:10px;background:transparent;color:#b91c1c;font:inherit;font-weight:600;cursor:pointer">🗑 مسح النقاط</button>
            </div>
            <p x-show="message" x-text="message" :style="messageKind === 'error' ? 'color:var(--subs-red)' : 'color:var(--subs-muted)'" style="font-size:13px;font-weight:600;margin:10px 0 0"></p>
            <p style="font-size:12.5px;color:var(--subs-muted);margin:8px 0 0">أو اضغط على الخريطة لإضافة نقطة يدوياً.</p>
        </x-filament::section>

        {{-- The current route: length and the points with their coordinates. --}}
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px">
                <div style="font-size:14px;font-weight:700;color:var(--subs-muted)">المسار الحالي</div>
                <div style="font-size:26px;font-weight:800;color:#0f766e" dir="ltr">
                    <span x-text="fmt(totalMeters)"></span> <span style="font-size:15px">م</span>
                </div>
            </div>

            <div x-ref="map" wire:ignore style="height:340px;border-radius:12px;margin-top:12px;background:#e5e7eb;z-index:0"></div>

            <template x-if="points.length">
                <div style="margin-top:12px">
                    <table style="width:100%;font-size:13.5px;border-collapse:collapse" dir="rtl">
                        <thead><tr>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">#</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">خط العرض (Lat)</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">خط الطول (Lng)</th>
                            <th style="text-align:right;padding:6px;color:var(--subs-muted)">من السابقة</th>
                            <th style="padding:6px"></th>
                        </tr></thead>
                        <tbody>
                            <template x-for="(p, i) in points" :key="i">
                                <tr style="border-top:1px solid var(--subs-subtle)">
                                    <td style="padding:6px;font-weight:700" x-text="i + 1"></td>
                                    <td style="padding:6px" dir="ltr" x-text="p.lat.toFixed(6)"></td>
                                    <td style="padding:6px" dir="ltr" x-text="p.lng.toFixed(6)"></td>
                                    <td style="padding:6px" dir="ltr" x-text="i === 0 ? '—' : fmt(segMeters(i)) + ' م'"></td>
                                    <td style="padding:6px"><button type="button" @click="copy(p.lat.toFixed(6) + ', ' + p.lng.toFixed(6))"
                                        style="padding:4px 10px;border:1px solid var(--subs-border);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12px;cursor:pointer">نسخ</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px">
                        <button type="button" @click="copyAll()"
                                style="padding:9px 16px;border:1px solid var(--subs-border);border-radius:10px;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer">📋 نسخ كل الإحداثيات</button>
                        <button type="button" @click="saveRoute()" x-show="points.length >= 2"
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
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">عدد النقاط</th>
                    <th style="text-align:right;padding:6px;color:var(--subs-muted)">الطول</th>
                    <th style="padding:6px"></th>
                </tr></thead>
                <tbody>
                    <template x-for="(r, i) in routes" :key="r.id">
                        <tr style="border-top:1px solid var(--subs-subtle)" :style="r.meters === shortest ? 'background:var(--subs-green-soft, #ecfdf5)' : ''">
                            <td style="padding:8px 6px;font-weight:700">
                                <span x-text="r.name"></span>
                                <span x-show="r.meters === shortest" style="color:var(--subs-green);font-size:12px;font-weight:700"> ✓ الأقصر</span>
                            </td>
                            <td style="padding:8px 6px" x-text="r.points.length"></td>
                            <td style="padding:8px 6px;font-weight:700;color:#0f766e" dir="ltr"><span x-text="fmt(r.meters)"></span> م</td>
                            <td style="padding:8px 6px;display:flex;gap:6px">
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
        window.routeMeasure = window.routeMeasure || function () {
            return {
                points: [], routes: [], map: null, line: null, markers: [],
                locating: false, message: '', messageKind: 'info',

                init() {
                    try { this.routes = JSON.parse(localStorage.getItem('subs_routes') || '[]') || []; } catch (e) { this.routes = []; }
                    this.loadLeaflet().then(() => this.$nextTick(() => this.createMap()))
                        .catch(() => this.say('تعذّر تحميل الخريطة. تحقق من الإنترنت.', 'error'));
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
                    this.map = L.map(this.$refs.map, { zoomControl: true }).setView([33.3152, 44.3661], 6);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19, attribution: '&copy; OpenStreetMap',
                    }).addTo(this.map);
                    this.map.on('click', (e) => this.addPoint(e.latlng.lat, e.latlng.lng));
                    setTimeout(() => this.map.invalidateSize(), 200);
                },

                // Metres between two lat/lng points (haversine).
                dist(a, b) {
                    const R = 6371000, toRad = (d) => d * Math.PI / 180;
                    const dLat = toRad(b.lat - a.lat), dLng = toRad(b.lng - a.lng);
                    const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
                    return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
                },
                lengthOf(pts) { let m = 0; for (let i = 1; i < pts.length; i++) m += this.dist(pts[i - 1], pts[i]); return m; },
                get totalMeters() { return this.lengthOf(this.points); },
                segMeters(i) { return i === 0 ? 0 : this.dist(this.points[i - 1], this.points[i]); },
                get shortest() { return this.routes.length ? Math.min(...this.routes.map(r => r.meters)) : null; },
                fmt(m) { return Math.round(m).toLocaleString('en-US'); },

                addPoint(lat, lng) {
                    if (!isFinite(lat) || !isFinite(lng)) return;
                    this.points.push({ lat, lng });
                    this.redraw(true);
                },
                undo() { this.points.pop(); this.redraw(false); },
                clearPoints() { this.points = []; this.redraw(false); },

                gpsPoint() {
                    if (!navigator.geolocation) { this.say('جهازك لا يدعم تحديد الموقع.', 'error'); return; }
                    this.locating = true; this.say('');
                    navigator.geolocation.getCurrentPosition(
                        (pos) => { this.locating = false; this.addPoint(pos.coords.latitude, pos.coords.longitude); this.say('أُضيفت نقطة من موقعك.'); },
                        (err) => { this.locating = false; this.say(err.code === 1 ? 'لم يُسمح بالوصول إلى موقعك. فعّل إذن الموقع.' : 'تعذّر تحديد موقعك. حاول في مكان مفتوح.', 'error'); },
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                    );
                },

                redraw(fit) {
                    const L = window.L;
                    if (!this.map || !L) return;
                    this.markers.forEach(m => this.map.removeLayer(m));
                    this.markers = [];
                    if (this.line) { this.map.removeLayer(this.line); this.line = null; }
                    const latlngs = this.points.map(p => [p.lat, p.lng]);
                    this.points.forEach((p, i) => {
                        const marker = L.marker([p.lat, p.lng], { draggable: true }).addTo(this.map).bindTooltip(String(i + 1), { permanent: true, direction: 'top' });
                        marker.on('dragend', (e) => { const ll = e.target.getLatLng(); this.points[i] = { lat: ll.lat, lng: ll.lng }; this.redraw(false); });
                        this.markers.push(marker);
                    });
                    if (latlngs.length >= 2) {
                        this.line = L.polyline(latlngs, { color: '#0f766e', weight: 4 }).addTo(this.map);
                        if (fit) this.map.fitBounds(this.line.getBounds().pad(0.3));
                    } else if (latlngs.length === 1 && fit) {
                        this.map.setView(latlngs[0], 18);
                    }
                },

                showRoute(r) {
                    this.points = r.points.map(p => ({ lat: p.lat, lng: p.lng }));
                    this.redraw(true);
                    this.say('عُرض «' + r.name + '». يمكنك تعديله ثم حفظه مساراً جديداً.');
                },

                saveRoute() {
                    if (this.points.length < 2) return;
                    const n = (this.routes.reduce((max, r) => Math.max(max, r.seq || 0), 0)) + 1;
                    this.routes.push({
                        id: (window.subsUuid ? subsUuid() : String(Date.now())), seq: n, name: 'مسار ' + n,
                        points: this.points.map(p => ({ lat: p.lat, lng: p.lng })), meters: Math.round(this.totalMeters),
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
                    this.copyText(this.points.map((p, i) => (i + 1) + ') ' + p.lat.toFixed(6) + ', ' + p.lng.toFixed(6)).join('\n'));
                    this.say('نُسخت كل الإحداثيات.');
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

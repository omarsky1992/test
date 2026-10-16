{{-- The map component of «موقع المشترك», defined before Alpine starts so it works with SPA navigation too. --}}
<script>
        // A fresh id per click (e.g. «إنشاء» a card), so a repeated request never does the work twice.
        window.subsUuid = window.subsUuid || function () {
            if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
            const b = crypto.getRandomValues(new Uint8Array(16));
            b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
            const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
            return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
        };

        window.subscriberLocationMap = window.subscriberLocationMap || function (config) {
            return {
                saved: config.saved, mode: config.mode, canEdit: config.canEdit,
                map: null, marker: null, circle: null, point: null,
                locating: false, saving: false, message: '', messageKind: 'info',

                init() {
                    if (this.mode === 'edit' || this.saved) {
                        this.boot();
                    }
                },

                // Leaflet is served by this server (public/vendor/leaflet), loaded once.
                loadLeaflet() {
                    if (window.L) return Promise.resolve();
                    window.__leafletLoading = window.__leafletLoading || new Promise((resolve, reject) => {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = @js(asset('vendor/leaflet/leaflet.css'));
                        document.head.appendChild(css);
                        const js = document.createElement('script');
                        js.src = @js(asset('vendor/leaflet/leaflet.js'));
                        js.onload = resolve;
                        js.onerror = () => { window.__leafletLoading = null; reject(); };
                        document.head.appendChild(js);
                    });
                    return window.__leafletLoading;
                },

                boot() {
                    if (this.map) {
                        this.$nextTick(() => this.map.invalidateSize());
                        return;
                    }
                    this.loadLeaflet().then(() => this.$nextTick(() => this.createMap())).catch(() => {
                        this.say('تعذّر تحميل الخريطة. تحقق من الإنترنت. يمكنك مع ذلك استخدام GPS وحفظ الموقع.', 'error');
                    });
                },

                createMap() {
                    const L = window.L;
                    // Without a saved point the map only opens on the region; no marker means no location.
                    const start = this.saved ? [this.saved.lat, this.saved.lng] : [33.3152, 44.3661];
                    this.map = L.map(this.$refs.map, { zoomControl: true }).setView(start, this.saved ? 18 : 6);
                    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                    }).addTo(this.map);
                    let tileErrors = 0;
                    tiles.on('tileerror', () => {
                        if (++tileErrors === 3) this.say('تعذّر تحميل صور الخريطة (الإنترنت ضعيف أو مقطوع). يبقى GPS يعمل.', 'error');
                    });
                    this.map.on('click', (e) => {
                        if (this.mode !== 'edit') return;
                        this.place(e.latlng.lat, e.latlng.lng, null, 'map');
                        this.say('حُددت النقطة على الخريطة. راجعها ثم احفظ.');
                    });
                    if (this.saved) {
                        this.showMarker(this.saved.lat, this.saved.lng, this.saved.accuracy, false);
                    }
                    this.$nextTick(() => this.map.invalidateSize());
                },

                showMarker(lat, lng, accuracy, draggable) {
                    const L = window.L;
                    if (!this.map) return;
                    if (this.marker) this.map.removeLayer(this.marker);
                    if (this.circle) this.map.removeLayer(this.circle);
                    this.marker = L.marker([lat, lng], { draggable }).addTo(this.map);
                    if (draggable) {
                        this.marker.on('dragend', () => {
                            const p = this.marker.getLatLng();
                            this.point = { lat: p.lat, lng: p.lng, accuracy: null, source: 'map' };
                            if (this.circle) { this.map.removeLayer(this.circle); this.circle = null; }
                            this.say('نُقلت العلامة يدوياً. راجعها ثم احفظ.');
                        });
                    }
                    if (accuracy) {
                        this.circle = L.circle([lat, lng], { radius: accuracy, weight: 1, fillOpacity: 0.12 }).addTo(this.map);
                    }
                },

                place(lat, lng, accuracy, source) {
                    this.point = { lat, lng, accuracy, source };
                    this.showMarker(lat, lng, accuracy, true);
                    if (this.map) this.map.setView([lat, lng], Math.max(this.map.getZoom(), 17));
                },

                locate() {
                    if (!window.isSecureContext) {
                        return this.say('تحديد الموقع يحتاج اتصالاً آمناً (https). حدّد البيت بالضغط على الخريطة.', 'error');
                    }
                    if (!('geolocation' in navigator)) {
                        return this.say('هذا الجهاز لا يدعم تحديد الموقع. حدّد البيت بالضغط على الخريطة.', 'error');
                    }
                    this.locating = true;
                    this.say('جارٍ تحديد موقعك… وافق على طلب الموقع إذا ظهر.');
                    navigator.geolocation.getCurrentPosition((pos) => {
                        this.locating = false;
                        const { latitude, longitude, accuracy } = pos.coords;
                        this.place(latitude, longitude, accuracy, 'gps');
                        this.say(accuracy > 50
                            ? 'تم تحديد موقعك بدقة ضعيفة (±' + Math.round(accuracy) + ' م). اسحب العلامة إلى البيت إن لزم ثم احفظ.'
                            : 'تم تحديد موقعك (±' + Math.round(accuracy) + ' م). راجع العلامة ثم احفظ.');
                    }, (err) => {
                        this.locating = false;
                        const reasons = {
                            1: 'رفضت إذن الموقع. فعّله من إعدادات المتصفح، أو حدّد البيت بالضغط على الخريطة.',
                            2: 'تعذّر تحديد الموقع (لا توجد إشارة GPS). حدّد البيت بالضغط على الخريطة.',
                            3: 'انتهت مهلة تحديد الموقع. حاول مجدداً في مكان مكشوف، أو حدّد البيت على الخريطة.',
                        };
                        this.say(reasons[err.code] || 'تعذّر تحديد الموقع. حدّد البيت بالضغط على الخريطة.', 'error');
                    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
                },

                save() {
                    if (!this.point) return this.say('حدّد الموقع أولاً من GPS أو بالضغط على الخريطة.', 'error');
                    let replace = false;
                    if (this.saved) {
                        if (!confirm('للمشترك موقع محفوظ. هل تريد استبداله بالموقع الجديد؟')) return;
                        replace = true;
                    }
                    this.saving = true;
                    this.$wire.saveLocation(this.point.lat, this.point.lng, this.point.accuracy, this.point.source, replace)
                        .finally(() => { this.saving = false; });
                },

                onSaved(detail) {
                    this.saved = { lat: detail.lat, lng: detail.lng, accuracy: detail.accuracy };
                    this.point = null;
                    this.mode = 'view';
                    this.say('حُفظ الموقع.');
                    this.showMarker(detail.lat, detail.lng, detail.accuracy, false);
                },

                onDeleted() {
                    this.saved = null;
                    this.point = null;
                    if (this.marker && this.map) this.map.removeLayer(this.marker);
                    if (this.circle && this.map) this.map.removeLayer(this.circle);
                    this.marker = this.circle = null;
                },

                setMode(mode) {
                    this.mode = mode;
                    this.message = '';
                    if (mode === 'edit') {
                        this.boot();
                        this.$nextTick(() => {
                            if (this.saved && this.map) this.showMarker(this.saved.lat, this.saved.lng, this.saved.accuracy, false);
                        });
                    } else {
                        this.point = null;
                        if (this.saved && this.map) this.showMarker(this.saved.lat, this.saved.lng, this.saved.accuracy, false);
                    }
                },

                say(text, kind = 'info') {
                    this.message = text;
                    this.messageKind = kind;
                },
            };
        };
</script>

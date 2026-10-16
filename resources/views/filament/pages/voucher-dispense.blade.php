@php
    $btn = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:12px;padding:12px 16px;font-weight:800;font-size:16px;border:none;cursor:pointer;text-decoration:none';
@endphp
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        @if ($canAdd)
            <a href="{{ \App\Filament\Resources\VoucherCards\VoucherCardResource::getUrl() }}" style="{{ $btn }};font-size:14px;padding:9px 14px;background:var(--subs-surface);border:1px solid var(--subs-border);color:var(--subs-text)">➕ إضافة أكواد / المخزون</a>
        @endif
        @if ($canHistory)
            <a href="{{ \App\Filament\Resources\DispensedVouchers\DispensedVoucherResource::getUrl() }}" style="{{ $btn }};font-size:14px;padding:9px 14px;background:var(--subs-surface);border:1px solid var(--subs-border);color:var(--subs-text)">🧾 سجل الكروت المصروفة</a>
        @endif
    </div>

    {{-- The card just taken: its code, big, with «نسخ» (the code only goes to the clipboard). --}}
    @if ($issued)
        <div wire:key="issued-{{ $issued['id'] }}" data-test="issued"
            x-data="{ copied: false, failed: false,
                copy() {
                    const code = this.$refs.code.value;
                    const done = () => { this.copied = true; this.failed = false; };
                    const manual = () => { this.failed = true; this.copied = false; this.$refs.code.focus(); this.$refs.code.select(); };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(code).then(done, () => this.legacy(code) ? done() : manual());
                    } else {
                        this.legacy(code) ? done() : manual();
                    }
                },
                legacy(code) {
                    try { this.$refs.code.focus(); this.$refs.code.select(); this.$refs.code.setSelectionRange(0, code.length); return document.execCommand('copy'); } catch (e) { return false; }
                } }"
            style="border:2px solid rgb(22 163 74);border-radius:16px;padding:14px;background:var(--tg-bg)">
            <div style="font-size:14px;color:var(--subs-text-2)">{{ \App\Models\VoucherCard::TYPES[$issued['type']] ?? $issued['type'] }} — {{ number_format($issued['denomination']) }} د.ع · صار الكارت مصروفاً</div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px">
                <input x-ref="code" readonly value="{{ $issued['code'] }}" data-test="code" dir="ltr" aria-label="كود الكارت"
                    x-on:focus="$event.target.select()"
                    style="flex:1;min-width:200px;font-family:ui-monospace,monospace;font-size:26px;font-weight:800;letter-spacing:2px;text-align:center;padding:10px;border:1px solid var(--subs-border);border-radius:12px;background:var(--subs-surface);color:var(--subs-text)">
                <button type="button" x-on:click="copy()" data-test="copy" style="{{ $btn }};min-width:120px;font-size:20px;background:rgb(22 163 74);color:white">📋 نسخ</button>
            </div>
            <div x-show="copied" x-cloak data-test="copied" style="margin-top:8px;font-weight:800;color:var(--subs-green)">✅ تم نسخ الكود بنجاح</div>
            <div x-show="failed" x-cloak style="margin-top:8px;font-size:13.5px;color:var(--subs-red)">تعذّر النسخ تلقائياً في هذا المتصفح. الكود محدد الآن: اضغط عليه مطولاً واختر «نسخ».</div>
            <button type="button" wire:click="clear" style="margin-top:10px;background:none;border:none;color:var(--subs-muted);font-size:13px;cursor:pointer">إخفاء</button>
        </div>
    @endif

    @if ($error)
        <div data-test="error" style="border-radius:12px;padding:12px;background:var(--tr-bg);color:var(--subs-red);font-weight:800">{{ $error }}</div>
    @endif

    @if ($categories->isEmpty())
        <p style="font-size:14px;color:var(--subs-muted)">لا توجد فئات بعد. أضف أكواد الكروت من «مخزون الكارتات».</p>
    @endif

    @foreach (\App\Models\VoucherCard::TYPES as $type => $label)
        @php $rows = $categories->where('type', $type); @endphp
        @if ($rows->isNotEmpty())
            <x-filament::section :heading="$label">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
                    @foreach ($rows as $c)
                        <div data-test="category-{{ $type }}-{{ $c->denomination }}" style="border:1px solid var(--subs-border);border-radius:14px;padding:12px;background:var(--subs-surface);text-align:center">
                            <div style="font-size:20px;font-weight:800">{{ number_format($c->denomination) }}</div>
                            <div style="font-size:12px;color:var(--subs-muted)">دينار</div>
                            <div style="margin:6px 0;font-size:13.5px">المتوفر: <b data-test="available" style="color:{{ $c->available > 0 ? 'var(--subs-green)' : 'var(--subs-red)' }}">{{ $c->available }}</b></div>
                            <button type="button" data-test="create"
                                x-data x-on:click="$wire.dispense(@js($type), {{ (int) $c->denomination }}, window.subsUuid())"
                                wire:loading.attr="disabled" wire:target="dispense"
                                style="{{ $btn }};width:100%;background:{{ $c->available > 0 ? 'var(--primary-600)' : 'rgb(168 162 158)' }};color:white">إنشاء</button>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endforeach
</x-filament-panels::page>

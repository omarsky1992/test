@php
    $btn = 'display:inline-flex;align-items:center;gap:6px;border-radius:10px;padding:9px 14px;font-weight:800;font-size:14px;text-decoration:none;border:1px solid transparent;cursor:pointer';
@endphp
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <a href="{{ $backUrl }}" style="{{ $btn }};background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-text)">→ صفحة المشترك</a>
        @if ($saved && $canDirections)
            <a href="{{ $location->directionsUrl() }}" target="_blank" rel="noopener" data-test="directions" style="{{ $btn }};background:rgb(22 163 74);color:white">🧭 فتح الاتجاهات</a>
        @endif
        @if ($mode === 'view' && $canEdit)
            <button type="button" wire:click="edit" style="{{ $btn }};background:var(--primary-600);color:white">{{ $saved ? '✏️ تعديل / استبدال الموقع' : '📍 إضافة موقع' }}</button>
        @endif
        @if ($mode === 'view' && $canDelete)
            <button type="button" wire:click="deleteLocation" wire:confirm="حذف موقع بيت هذا المشترك؟ يبقى أثر الحذف في سجل العمليات." style="{{ $btn }};background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-red)">🗑️ حذف الموقع</button>
        @endif
    </div>

    @if ($canSee || $canEdit)
        <x-filament::section>
            <x-slot name="heading">{{ $mode === 'edit' ? ($saved ? 'تعديل موقع البيت' : 'إضافة موقع البيت') : 'موقع البيت' }}</x-slot>
            @if ($location?->hasCoordinates() && $canSee)
                <x-slot name="description">آخر تحديث {{ $location->saved_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }} بواسطة {{ $location->saver?->name ?? '—' }}@if ($location->accuracy_m) · دقة GPS نحو {{ $location->accuracy_m }} م@endif · {{ $location->source === 'gps' ? 'من GPS' : 'من الخريطة' }}</x-slot>
            @endif

            {{-- The map: managed by Alpine and Leaflet (served from this server), never re-rendered by Livewire. --}}
            <div wire:ignore
                x-data="subscriberLocationMap({ saved: @js($saved), mode: @js($mode), canEdit: @js($canEdit) })"
                x-on:location-mode.window="setMode($event.detail.mode)"
                x-on:location-saved.window="onSaved($event.detail)"
                x-on:location-deleted.window="onDeleted()">
                <div x-show="mode === 'edit'" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                    <button type="button" x-on:click="locate()" x-bind:disabled="locating" data-test="gps"
                        style="{{ $btn }};background:rgb(37 99 235);color:white">
                        <span x-text="locating ? 'جارٍ تحديد موقعك…' : '🎯 تحديد موقعي الحالي GPS'"></span>
                    </button>
                    <button type="button" x-on:click="save()" x-bind:disabled="!point || saving" data-test="save"
                        style="{{ $btn }};background:rgb(22 163 74);color:white">
                        <span x-text="saving ? 'جارٍ الحفظ…' : '💾 حفظ الموقع'"></span>
                    </button>
                    <button type="button" x-on:click="$wire.cancelEdit()" style="{{ $btn }};background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-text)">إلغاء</button>
                </div>
                <div x-show="message" x-text="message" data-test="message"
                    x-bind:style="'margin-bottom:8px;padding:8px 10px;border-radius:10px;font-size:13.5px;' + (messageKind === 'error' ? 'background:var(--tr-bg);color:var(--subs-red)' : 'background:var(--tt-bg);color:var(--subs-text)')"></div>
                <div x-show="mode === 'edit'" style="font-size:12.5px;color:var(--subs-muted);margin-bottom:6px">
                    اضغط «تحديد موقعي الحالي» وأنت عند البيت. إذا كانت الإشارة غير دقيقة اسحب العلامة أو اضغط على الخريطة لتحديد البيت، ثم احفظ.
                </div>
                <div x-show="mode === 'edit' || saved" x-ref="map" data-test="map" style="height:62vh;min-height:320px;border-radius:14px;border:1px solid var(--subs-border);overflow:hidden;z-index:0"></div>
                <div x-show="point" style="font-size:12px;color:var(--subs-muted);margin-top:6px" dir="ltr" x-text="point ? point.lat.toFixed(6) + ', ' + point.lng.toFixed(6) + (point.accuracy ? '  (±' + Math.round(point.accuracy) + ' m)' : '') : ''"></div>
                @if (! $saved)
                    <p x-show="mode === 'view' && !saved" style="font-size:14px;color:var(--subs-muted)">لا يوجد موقع محفوظ لهذا المشترك.</p>
                @endif
            </div>
        </x-filament::section>
    @endif

    @if ($canSeePhoto || $canAddPhoto)
        <x-filament::section heading="صورة البيت (اختيارية)">
            @if ($location?->photo_path && $canSeePhoto)
                <a href="{{ route('subscribers.house-photo', $subscriberModel) }}" target="_blank" rel="noopener">
                    <img src="{{ route('subscribers.house-photo', $subscriberModel) }}?v={{ $location->photo_at?->timestamp }}" alt="صورة البيت" data-test="photo"
                        style="max-width:100%;max-height:340px;border-radius:12px;border:1px solid var(--subs-border)">
                </a>
                <div style="font-size:12.5px;color:var(--subs-muted);margin-top:4px">أضافها {{ $location->photographer?->name ?? '—' }} في {{ $location->photo_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }}</div>
                @if ($canDeletePhoto)
                    <button type="button" wire:click="deletePhoto" wire:confirm="حذف صورة البيت؟" style="{{ $btn }};margin-top:8px;background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-red)">🗑️ حذف الصورة</button>
                @endif
            @elseif (! $location?->photo_path)
                <p style="font-size:13.5px;color:var(--subs-muted)">لا توجد صورة. الصورة غير إجبارية: يمكن حفظ الموقع بدونها.</p>
            @endif

            @if ($canAddPhoto)
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;align-items:center">
                    <label style="{{ $btn }};background:rgb(37 99 235);color:white">📷 التقاط صورة
                        <input type="file" accept="image/*" capture="environment" wire:model="photo" style="display:none">
                    </label>
                    <label style="{{ $btn }};background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-text)">🖼️ اختيار من المعرض
                        <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="photo" data-test="photo-input" style="display:none">
                    </label>
                    <span wire:loading wire:target="photo" style="font-size:13px">جارٍ رفع الصورة…</span>
                </div>
                @error('photo') <p style="color:var(--subs-red);font-size:13px;margin-top:6px">{{ $message }}</p> @enderror
                @if ($photo)
                    <div style="margin-top:10px">
                        @if (method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                            <img src="{{ $photo->temporaryUrl() }}" alt="معاينة" style="max-width:100%;max-height:240px;border-radius:12px">
                        @endif
                        <div style="display:flex;gap:8px;margin-top:6px">
                            <button type="button" wire:click="savePhoto" data-test="save-photo" style="{{ $btn }};background:rgb(22 163 74);color:white">💾 حفظ الصورة</button>
                            <button type="button" wire:click="$set('photo', null)" style="{{ $btn }};background:var(--subs-surface);border-color:var(--subs-border);color:var(--subs-text)">تخطّي</button>
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>
    @endif

</x-filament-panels::page>

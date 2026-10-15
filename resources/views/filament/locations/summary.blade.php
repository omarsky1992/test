@php
    $subscriber = $getRecord();
    $location = $subscriber->location;
    $user = auth()->user();
    $hasPoint = $location?->hasCoordinates() && $user->can('locations.view');
    $btn = 'display:inline-flex;align-items:center;gap:6px;border-radius:10px;padding:8px 12px;font-weight:800;font-size:13.5px;text-decoration:none';
    $page = fn (string $mode) => \App\Filament\Pages\SubscriberLocationPage::getUrl(['subscriber' => $subscriber->id, 'mode' => $mode]);
@endphp
<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start" data-test="location-summary">
    <div style="flex:1;min-width:220px">
        @if ($hasPoint)
            <div style="font-size:14px">📍 موقع البيت محفوظ</div>
            <div style="font-size:12.5px;color:var(--subs-muted);margin-top:2px">آخر تحديث {{ $location->saved_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i') }} — {{ $location->saver?->name ?? '—' }}</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
                <a href="{{ $page('view') }}" style="{{ $btn }};background:var(--primary-600);color:white">🗺️ عرض الموقع</a>
                @can('locations.directions')
                    <a href="{{ $location->directionsUrl() }}" target="_blank" rel="noopener" style="{{ $btn }};background:rgb(22 163 74);color:white">🧭 فتح الاتجاهات</a>
                @endcan
                @can('locations.update')
                    <a href="{{ $page('edit') }}" style="{{ $btn }};border:1px solid var(--subs-border);color:var(--subs-text)">✏️ تعديل</a>
                @endcan
            </div>
        @elseif ($user->can('locations.view') || $user->can('locations.create'))
            <div style="font-size:14px;color:var(--subs-muted)">لا يوجد موقع محفوظ لهذا المشترك.</div>
            @can('locations.create')
                <a href="{{ $page('edit') }}" style="{{ $btn }};margin-top:8px;background:var(--primary-600);color:white">📍 إضافة موقع</a>
            @endcan
        @endif
    </div>
    @if ($location?->photo_path && $user->can('photos.view'))
        <a href="{{ $page('view') }}"><img src="{{ route('subscribers.house-photo', $subscriber) }}?v={{ $location->photo_at?->timestamp }}" alt="صورة البيت" style="width:140px;height:105px;object-fit:cover;border-radius:10px;border:1px solid var(--subs-border)"></a>
    @elseif ($user->can('photos.add') && ($location?->hasCoordinates() || $user->can('locations.create')))
        <a href="{{ $page('view') }}" style="font-size:13px;color:var(--subs-muted)">📷 إضافة صورة للبيت (اختياري)</a>
    @endif
</div>

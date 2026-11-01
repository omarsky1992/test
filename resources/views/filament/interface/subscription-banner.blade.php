@php
    $tenant = app(\App\Platform\TenantManager::class)->current();
    $message = $tenant && auth()->user()?->hasRole('admin') ? \App\Platform\SubscriptionAlerts::message($tenant) : null;
@endphp
@if ($message)
    <div data-test="subscription-banner" style="margin:0 0 14px;padding:12px 16px;border-radius:12px;background:#fef3c7;color:#78350f;border:1px solid #fcd34d;font-weight:600;font-size:14px">
        ⚠️ {{ $message }}
    </div>
@endif

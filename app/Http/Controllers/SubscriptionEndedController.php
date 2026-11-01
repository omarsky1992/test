<?php

namespace App\Http\Controllers;

use App\Platform\PlatformSettings;
use App\Platform\Subscription;
use App\Platform\TenantManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** «انتهى الاشتراك»: what a stopped client sees, with the end date and how to renew. */
class SubscriptionEndedController extends Controller
{
    public function __invoke(TenantManager $tenants, PlatformSettings $settings): View|RedirectResponse
    {
        $tenant = $tenants->current();
        $state = $tenant ? Subscription::state($tenant) : null;
        if (! in_array($state, ['expired', 'suspended'], true)) {
            return redirect('/'); // running normally (or client 1): nothing to show
        }

        return view('subscription-ended', [
            'tenant' => $tenant,
            'suspended' => $state === 'suspended',
            'endedAt' => Subscription::stopsAt($tenant)?->setTimezone(Subscription::tz())->format('Y/m/d'),
            'contactName' => $settings->get('contact.name'),
            'contactPhone' => $settings->get('contact.phone'),
            'contactMessage' => $settings->get('contact.message'),
            'signedIn' => auth()->check(),
        ]);
    }
}

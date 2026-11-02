<?php

namespace App\Http\Middleware;

use App\Platform\Subscription;
use App\Platform\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First thing on every request: the client comes from the address (never from anything the browser
 * sends in the request body), and the request runs on that client's database only. An address that
 * belongs to no client gets 404. A client whose subscription has ended (after any grace days) or
 * that the owner suspended can open only the «انتهى الاشتراك» page and sign out; everything else is
 * refused here, on the server, whatever the browser shows. Its data stays untouched.
 */
class IdentifyTenant
{
    /** What a stopped client may still reach. */
    private const ALLOWED_ROUTES = ['subscription.ended', 'filament.admin.auth.logout'];

    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tenants->identify($request->getHost(), $request->path()), 404);

        $tenant = $this->tenants->current();
        if ($tenant !== null && in_array(Subscription::state($tenant), ['expired', 'suspended'], true) && ! $this->allowed($request)) {
            return $request->isMethod('GET') && ! $request->expectsJson() && ! $request->hasHeader('X-Livewire')
                ? redirect()->route('subscription.ended')
                : response()->json(['message' => 'انتهى الاشتراك. تواصل مع إدارة المنصة للتجديد.'], 403);
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        $route = $request->route()?->getName();
        if ($route === null) {
            // Not matched yet (global middleware runs before routing): match it here, by name only.
            $route = rescue(fn () => app('router')->getRoutes()->match($request)->getName(), null, report: false);
        }

        return in_array($route, self::ALLOWED_ROUTES, true) || $request->is('up');
    }
}

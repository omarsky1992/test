<?php

namespace App\Http\Middleware;

use App\Platform\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First thing on every request: the client comes from the address (never from anything the browser
 * sends in the request body), and the request runs on that client's database only. An address that
 * belongs to no client gets 404.
 */
class IdentifyTenant
{
    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tenants->identify($request->getHost()), 404);

        return $next($request);
    }
}

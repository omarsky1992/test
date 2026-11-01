<?php

namespace App\Platform;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** The platform owner's actions, append-only (passwords and secrets are never written). */
class PlatformAudit
{
    private const HIDDEN = ['password', 'password_confirmation', 'admin_password'];

    public function log(string $action, ?Tenant $tenant = null, ?array $old = null, ?array $new = null): void
    {
        DB::connection('platform')->table('platform_audit_logs')->insert([
            'occurred_at' => now(),
            'admin_id' => Auth::guard('platform')->id(),
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'old_values' => $old === null ? null : json_encode(array_diff_key($old, array_flip(self::HIDDEN)), JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode(array_diff_key($new, array_flip(self::HIDDEN)), JSON_UNESCAPED_UNICODE),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}

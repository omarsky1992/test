<?php

namespace App\Platform;

use Illuminate\Support\Facades\DB;

/** The counts the owner sees per client, read (never written) from each client's database. */
class TenantStats
{
    public function refresh(Tenant $tenant): Tenant
    {
        config(['database.connections.tenant_stats' => ['database' => $tenant->database] + config('database.connections.platform')]);
        try {
            $db = DB::connection('tenant_stats');
            $tenant->forceFill([
                'subscribers_count' => (int) $db->table('subscribers')->count(),
                'users_count' => (int) $db->table('users')->where('is_active', true)->count(),
                'stats_at' => now(),
            ])->saveQuietly();
        } finally {
            DB::purge('tenant_stats');
        }

        return $tenant;
    }

    public function refreshAll(): int
    {
        $count = 0;
        foreach (Tenant::where('status', '<>', 'archived')->get() as $tenant) {
            rescue(fn () => $this->refresh($tenant), report: true);
            $count++;
        }

        return $count;
    }
}

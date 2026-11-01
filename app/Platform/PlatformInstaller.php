<?php

namespace App\Platform;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Sets up the platform database and registers the original system as client 1. Safe to run on every
 * start: it creates only what is missing and never writes to a client's database.
 */
class PlatformInstaller
{
    public const FIRST_SLUG = 'main';

    /** @return array{created_database: bool, tenant: Tenant} */
    public function install(): array
    {
        $created = $this->ensureDatabase();
        Artisan::call('migrate', ['--database' => 'platform', '--path' => 'database/migrations/platform', '--force' => true]);

        return ['created_database' => $created, 'tenant' => $this->registerFirstTenant()];
    }

    /** Creates the platform database on the same server when it does not exist yet. */
    private function ensureDatabase(): bool
    {
        $name = (string) config('database.connections.platform.database');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name)) {
            throw new \RuntimeException("Invalid platform database name: {$name}");
        }
        // Its own connection: CREATE DATABASE cannot run inside a transaction of the main one.
        config(['database.connections.platform_bootstrap' => config('database.connections.'.config('database.default'))]);
        $server = DB::connection('platform_bootstrap');
        try {
            if ($server->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$name])) {
                return false;
            }
            $server->statement("CREATE DATABASE \"{$name}\"");
            DB::purge('platform');

            return true;
        } finally {
            DB::purge('platform_bootstrap');
        }
    }

    /**
     * The existing system, its database and its address, as client 1: permanent (the owner's own
     * business), never re-created and never changed once registered.
     */
    private function registerFirstTenant(): Tenant
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        return Tenant::where('database', $database)->first() ?? Tenant::create([
            'slug' => self::FIRST_SLUG,
            'name' => $this->companyName() ?? 'العميل الأول',
            'domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            'database' => $database,
            'status' => 'active',
            'is_permanent' => true,
            'subscription_starts_at' => now(),
        ]);
    }

    /** Read only: the agent's name from client 1's settings, when set. */
    private function companyName(): ?string
    {
        $value = DB::connection(config('database.default'))->table('settings')->where('key', 'company.name')->value('value');
        $name = is_string($value) ? json_decode($value, true) : null;

        return is_string($name) && trim($name) !== '' && ! str_starts_with($name, '[') ? $name : null;
    }
}

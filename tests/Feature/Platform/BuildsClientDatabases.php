<?php

namespace Tests\Feature\Platform;

use App\Platform\PlatformInstaller;
use App\Platform\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Real databases for the platform tests: client 1 («subs_test_home»), a second client and the
 * platform database, built once per test class and never wrapped in a transaction.
 */
trait BuildsClientDatabases
{
    protected const HOME = 'subs_test_home';

    protected const SECOND = 'subs_test_second';

    private static bool $built = false;

    protected function setUpClients(): void
    {
        config([
            'platform.home_database' => self::HOME,
            'database.connections.pgsql.database' => self::HOME,
            'platform.admin_host' => 'platform.localhost',
            'platform.base_domain' => 'localhost',
            'whatsapp.waha.api_key' => 'home-waha-key',
            'backup.server_dir' => '/backups',
        ]);
        DB::purge('pgsql');
        if (self::$built) {
            return;
        }
        $leftovers = array_column($this->server()->select("SELECT datname FROM pg_database WHERE datname LIKE 'subs_test_home_t_%'"), 'datname');
        foreach (['subs_test_platform', self::HOME, self::SECOND, ...$leftovers] as $name) {
            $this->server()->statement("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
        }
        foreach ([self::HOME, self::SECOND] as $name) {
            $this->server()->statement("CREATE DATABASE \"{$name}\"");
            config(["database.connections.build_{$name}" => ['database' => $name] + config('database.connections.pgsql')]);
            Artisan::call('migrate', ['--database' => "build_{$name}", '--force' => true]);
            Artisan::call('db:seed', ['--database' => "build_{$name}", '--force' => true]);
            DB::purge("build_{$name}");
        }
        DB::purge('pgsql');
        app(PlatformInstaller::class)->install();
        Tenant::create(['slug' => 'wakeel2', 'name' => 'الوكيل الثاني', 'domain' => 'wakeel2.test', 'database' => self::SECOND]);
        self::$built = true;
    }

    public static function tearDownAfterClass(): void
    {
        self::$built = false;
        parent::tearDownAfterClass();
    }

    protected function server()
    {
        config(['database.connections.server' => ['database' => 'postgres'] + config('database.connections.pgsql')]);

        return DB::connection('server');
    }
}

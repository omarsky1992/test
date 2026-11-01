<?php

namespace Tests\Feature\Platform;

use App\Platform\ClientBackups;
use App\Platform\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

/** Certificates only for real clients' addresses, and the daily dumps of every client's database. */
class PlatformOperationsTest extends BaseTestCase
{
    use BuildsClientDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClients();
        Tenant::where('slug', 'wakeel2')->update(['status' => 'active']);
    }

    public function test_caddy_gets_a_certificate_only_for_a_registered_clients_address(): void
    {
        $this->get('http://app/platform/tls-check?domain=wakeel2.test')->assertOk();
        $this->get('http://app/platform/tls-check?domain=WAKEEL2.test.')->assertOk();
        $this->get('http://app/platform/tls-check?domain=nobody.test')->assertNotFound();
        $this->get('http://app/platform/tls-check')->assertNotFound();

        Tenant::where('slug', 'wakeel2')->update(['status' => 'archived']);
        $this->get('http://app/platform/tls-check?domain=wakeel2.test')->assertNotFound();

        // Only from inside the server.
        Tenant::where('slug', 'wakeel2')->update(['status' => 'active']);
        $this->get('http://localhost/platform/tls-check?domain=wakeel2.test')->assertNotFound();
    }

    public function test_the_daily_backup_dumps_the_platform_and_every_client_but_client_1(): void
    {
        $dir = storage_path('framework/testing/platform-backups');
        File::deleteDirectory($dir);
        $fake = storage_path('framework/testing/fake-pg_dump');
        File::ensureDirectoryExists(dirname($fake));
        // Stands in for pg_dump: writes the database name into the --file it is given, and logs its arguments.
        File::put($fake, "#!/bin/sh\nfor a in \"\$@\"; do case \$a in --file=*) f=\${a#--file=};; esac; db=\$a; done\necho \"\$db\" > \"\$f\"\necho \"\$@\" >> \"\$(dirname \$0)/pg_dump.log\"\n");
        chmod($fake, 0755);
        @unlink(dirname($fake).'/pg_dump.log');
        config(['platform.backup_dir' => $dir, 'platform.backup_keep' => 2, 'backup.pg_dump' => $fake]);

        $results = app(ClientBackups::class)->run();

        $this->assertSame(['subs_test_platform', self::SECOND], array_keys($results));
        $this->assertSame(self::SECOND, trim(file_get_contents($results[self::SECOND])));
        $log = file_get_contents(dirname($fake).'/pg_dump.log');
        $this->assertStringNotContainsString(self::HOME, $log, 'client 1 has its own daily dumps');
        $this->assertStringNotContainsStringIgnoringCase('password', $log, 'no password on the command line');

        // The newest two are kept.
        foreach (['2026-01-01_0300', '2026-01-02_0300', '2026-01-03_0300'] as $old) {
            File::put("{$dir}/".self::SECOND.'/'.self::SECOND."-{$old}.dump", 'old');
        }
        app(ClientBackups::class)->run();
        $this->assertCount(2, glob("{$dir}/".self::SECOND.'/*.dump'));
        $this->artisan('platform:backup')->assertSuccessful();
    }
}

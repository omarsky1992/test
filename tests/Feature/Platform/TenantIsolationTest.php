<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Platform\PlatformInstaller;
use App\Platform\Tenant;
use App\Platform\TenantManager;
use App\Services\SubscriberService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Two clients on two real databases: client 1 (the original system, here «subs_test_home») and a
 * second client. Not wrapped in a transaction: every database is real and dropped at the end.
 */
class TenantIsolationTest extends BaseTestCase
{
    private const HOME = 'subs_test_home';

    private const SECOND = 'subs_test_second';

    private static bool $built = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'platform.home_database' => self::HOME,
            'database.connections.pgsql.database' => self::HOME,
            'whatsapp.waha.api_key' => 'home-waha-key',
            'backup.server_dir' => '/backups',
        ]);
        DB::purge('pgsql');
        if (! self::$built) {
            foreach (['subs_test_platform', self::HOME, self::SECOND] as $name) {
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
    }

    public static function tearDownAfterClass(): void
    {
        self::$built = false;
        parent::tearDownAfterClass();
    }

    private function server()
    {
        config(['database.connections.server' => ['database' => 'postgres'] + config('database.connections.pgsql')]);

        return DB::connection('server');
    }

    private function tenants(): TenantManager
    {
        return app(TenantManager::class);
    }

    private function second(): Tenant
    {
        return Tenant::where('slug', 'wakeel2')->sole();
    }

    /** Signs in as that client's admin, on that client. */
    private function signInTo(?Tenant $tenant): User
    {
        $this->tenants()->activate($tenant);
        $admin = User::where('username', 'admin')->sole();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_each_address_opens_its_own_clients_database(): void
    {
        $this->assertTrue($this->tenants()->identify('localhost'));
        $this->assertSame(self::HOME, DB::connection()->getDatabaseName());
        $this->assertTrue($this->tenants()->identify('wakeel2.test'));
        $this->assertSame(self::SECOND, DB::connection()->getDatabaseName());
        $this->assertTrue($this->tenants()->identify('app')); // the WhatsApp webhook from inside the server
        $this->assertSame(self::HOME, DB::connection()->getDatabaseName());

        $this->get('http://nobody.test/login')->assertNotFound();
        $this->get('http://wakeel2.test/login')->assertOk();
    }

    public function test_a_client_never_sees_another_clients_subscribers(): void
    {
        $this->signInTo(null);
        app(SubscriberService::class)->create(['full_name' => 'مشترك العميل الأول', 'phone' => '07701110001']);
        $this->signInTo($this->second());
        app(SubscriberService::class)->create(['full_name' => 'مشترك العميل الثاني', 'phone' => '07701110002']);

        $this->get('http://wakeel2.test/subscribers')->assertOk()
            ->assertSee('مشترك العميل الثاني')->assertDontSee('مشترك العميل الأول');

        $this->signInTo(null);
        $this->get('http://localhost/subscribers')->assertOk()
            ->assertSee('مشترك العميل الأول')->assertDontSee('مشترك العميل الثاني');
    }

    public function test_a_session_or_id_from_one_client_opens_nothing_on_another(): void
    {
        $this->signInTo($this->second());
        $mine = app(SubscriberService::class)->create(['full_name' => 'سجل خاص بالثاني', 'phone' => '07701110003']);

        // Signed in on the second client, the same browser asks client 1 for that record: not signed in there.
        $this->get("http://localhost/subscribers/{$mine->id}")->assertRedirect();
        $this->assertGuest();

        // Signed in on client 1, the second client's address with an id from client 1 shows only the second's own data.
        $this->signInTo(null);
        $home = app(SubscriberService::class)->create(['full_name' => 'سجل خاص بالأول', 'phone' => '07701110004']);
        $this->get("http://wakeel2.test/subscribers/{$home->id}")->assertDontSee('سجل خاص بالأول');
        $this->get('http://wakeel2.test/subscribers')->assertDontSee('سجل خاص بالأول');
    }

    public function test_passwords_belong_to_their_own_client(): void
    {
        $this->tenants()->activate($this->second());
        User::where('username', 'admin')->sole()->update(['password' => 'second-client-pass']);

        $this->assertTrue(Auth::guard('web')->attempt(['username' => 'admin', 'password' => 'second-client-pass']));
        $this->tenants()->activate(null);
        $this->assertFalse(Auth::guard('web')->attempt(['username' => 'admin', 'password' => 'second-client-pass']));
        $this->assertTrue(Auth::guard('web')->attempt(['username' => 'admin', 'password' => 'test-password']));
    }

    public function test_files_whatsapp_and_server_backups_stay_with_client_1(): void
    {
        $this->tenants()->activate($this->second());
        Storage::disk('local')->put('probe.txt', 'second');
        $this->assertNull(config('whatsapp.waha.api_key'));
        $this->assertNull(config('backup.server_dir'));
        $this->assertStringContainsString('tenants/wakeel2', Storage::disk('local')->path('probe.txt'));

        $this->tenants()->activate(null);
        $this->assertFalse(Storage::disk('local')->exists('probe.txt'));
        $this->assertSame('home-waha-key', config('whatsapp.waha.api_key'));
        $this->assertSame('/backups', config('backup.server_dir'));
    }

    public function test_scheduled_commands_run_once_per_client_on_its_own_database(): void
    {
        Artisan::call('tenants:each', ['task' => 'tenants:current']);
        $output = Artisan::output();

        $this->assertStringContainsString('[main] main '.self::HOME, $output);
        $this->assertStringContainsString('[wakeel2] wakeel2 '.self::SECOND, $output);
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Models\Subscriber;
use App\Models\User;
use App\Platform\PlatformInstaller;
use App\Platform\Tenant;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformInstallTest extends TestCase
{
    private const DATABASE = 'subs_test_platform';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropPlatformDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropPlatformDatabase();
        parent::tearDown();
    }

    private function dropPlatformDatabase(): void
    {
        DB::purge('platform');
        config(['database.connections.drop_platform' => config('database.connections.pgsql')]);
        DB::connection('drop_platform')->statement('DROP DATABASE IF EXISTS "'.self::DATABASE.'" WITH (FORCE)');
        DB::purge('drop_platform');
    }

    public function test_the_platform_database_is_created_and_the_current_system_becomes_client_1(): void
    {
        app(Settings::class)->set('company.name', 'وكيل الأمل');
        $this->account('علي حسين');
        $before = $this->clientOneSnapshot();

        $result = app(PlatformInstaller::class)->install();

        $this->assertTrue($result['created_database']);
        $tenant = Tenant::sole();
        $this->assertSame(['main', 'وكيل الأمل', 'subs_test', true, 'active', null], [
            $tenant->slug, $tenant->name, $tenant->database, $tenant->is_permanent, $tenant->status, $tenant->subscription_ends_at,
        ]);
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), $tenant->domain);
        $this->assertSame($before, $this->clientOneSnapshot(), 'client 1 data is untouched');
    }

    public function test_running_it_again_changes_nothing_and_adds_no_second_client(): void
    {
        app(PlatformInstaller::class)->install();
        Tenant::sole()->update(['name' => 'اسم غيّره المالك']);

        $result = app(PlatformInstaller::class)->install();
        Artisan::call('platform:install');

        $this->assertFalse($result['created_database']);
        $this->assertSame(['اسم غيّره المالك'], Tenant::pluck('name')->all());
    }

    public function test_the_platform_audit_log_cannot_be_edited(): void
    {
        app(PlatformInstaller::class)->install();
        $id = DB::connection('platform')->table('platform_audit_logs')->insertGetId(['occurred_at' => now(), 'action' => 'test']);

        $this->expectException(QueryException::class);
        DB::connection('platform')->table('platform_audit_logs')->where('id', $id)->update(['action' => 'changed']);
    }

    /** What client 1 holds: its tables and the rows that matter. */
    private function clientOneSnapshot(): array
    {
        return [
            array_column(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY 1"), 'table_name'),
            User::orderBy('id')->get(['id', 'username', 'password'])->toArray(),
            Subscriber::orderBy('id')->get(['id', 'full_name'])->toArray(),
            DB::table('settings')->orderBy('key')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\Tenants\Pages\ListTenants;
use App\Models\Subscriber;
use App\Models\User;
use App\Platform\PlatformAdmin;
use App\Platform\Subscription;
use App\Platform\Tenant;
use App\Platform\TenantManager;
use App\Platform\TenantProvisioner;
use App\Services\Settings;
use App\Services\SubscriberService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** The platform owner's panel: its own accounts, adding clients, subscriptions, suspending, archiving. */
class PlatformPanelTest extends BaseTestCase
{
    use BuildsClientDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClients();
    }

    private function owner(): PlatformAdmin
    {
        return PlatformAdmin::firstOrCreate(['username' => 'owner'], ['name' => 'مالك المنصة', 'password' => 'owner-password-123', 'is_active' => true]);
    }

    /** On the platform address, signed in as the owner. */
    private function asOwner(): void
    {
        app(TenantManager::class)->activatePlatform();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($this->owner(), 'platform');
    }

    public function test_the_platform_has_its_own_address_accounts_and_database(): void
    {
        $this->get('http://platform.localhost/login')->assertOk();
        $this->assertSame('filament.platform.auth.login', request()->route()->getName());
        $this->get('http://platform.localhost/')->assertRedirect('http://platform.localhost/login');
        $this->assertSame('subs_test_platform', DB::connection()->getDatabaseName(), 'the platform address never opens a client database');

        // A client's admin is not a platform account, and the owner is not a client's user.
        $this->assertFalse(Auth::guard('platform')->attempt(['username' => 'admin', 'password' => 'test-password']));
        $this->owner();
        app(TenantManager::class)->activate(null);
        $this->assertFalse(Auth::guard('web')->attempt(['username' => 'owner', 'password' => 'owner-password-123']));
        $this->assertFalse(User::where('username', 'admin')->sole()->canAccessPanel(Filament::getPanel('platform')));

        $this->asOwner();
        $this->get('http://platform.localhost/tenants')->assertOk()->assertSee('الوكيل الثاني');
    }

    public function test_adding_a_client_builds_its_own_empty_system_with_its_manager(): void
    {
        app(TenantManager::class)->activate(null);
        $this->actingAs(User::where('username', 'admin')->sole());
        app(SubscriberService::class)->create(['full_name' => 'مشترك عند العميل الأول', 'phone' => '07701112222']);

        $this->asOwner();
        Livewire::test(ListTenants::class)
            ->callAction('createTenant', [
                'name' => 'وكالة الأمل', 'owner_name' => 'حسن علي', 'phone' => '07801234567', 'slug' => 'alamal',
                'username' => 'hassan', 'password' => 'Temp-Pass-2026', 'duration' => '3m', 'starts_on' => '2026-11-01',
                'trial_days' => 0, 'grace_days' => 2,
            ])->assertHasNoActionErrors()->assertNotified();

        $tenant = Tenant::where('slug', 'alamal')->sole();
        $this->assertSame(['alamal.localhost', 'subs_test_home_t_alamal', 'active'], [$tenant->domain, $tenant->database, $tenant->status]);
        $this->assertSame('2027-02-01', $tenant->subscription_ends_at->setTimezone('Asia/Baghdad')->toDateString());
        $this->assertSame([0, 1], [$tenant->subscribers_count, $tenant->users_count]);

        // Its manager signs in on its address only; nothing of client 1 was copied.
        app(TenantManager::class)->activate($tenant);
        $this->assertTrue(Auth::guard('web')->attempt(['username' => 'hassan', 'password' => 'Temp-Pass-2026']));
        $this->assertSame('وكالة الأمل', app(Settings::class)->get('company.name'));
        $this->assertSame(0, Subscriber::count());
        $this->assertTrue(User::where('username', 'hassan')->sole()->hasRole('admin'));
        app(TenantManager::class)->activate(null);
        $this->assertFalse(Auth::guard('web')->attempt(['username' => 'hassan', 'password' => 'Temp-Pass-2026']));

        // Logged without the password.
        $log = DB::connection('platform')->table('platform_audit_logs')->where('action', 'tenant.created')->sole();
        $this->assertStringNotContainsString('Temp-Pass-2026', $log->new_values);
    }

    public function test_a_bad_or_taken_address_creates_nothing(): void
    {
        $before = count($this->server()->select('SELECT datname FROM pg_database'));
        foreach (['platform', 'A b', 'wakeel2', 'x'] as $slug) {
            try {
                app(TenantProvisioner::class)->create(['name' => 'س', 'slug' => $slug, 'username' => 'u1', 'password' => 'long-enough-1', 'starts_at' => CarbonImmutable::now()]);
                $this->fail("{$slug} was accepted");
            } catch (ValidationException) {
            }
        }
        $this->assertCount($before, $this->server()->select('SELECT datname FROM pg_database'));
    }

    public function test_provisioning_never_runs_on_client_1_or_a_database_with_users(): void
    {
        $run = fn (string $slug) => Process::path(base_path())->env(['TENANT' => $slug, 'PROVISION_USERNAME' => 'x', 'PROVISION_PASSWORD' => 'xxxxxxxxxx'])
            ->run([PHP_BINARY, 'artisan', 'platform:provision']);

        $this->assertTrue($run('main')->failed());
        $this->assertTrue($run('wakeel2')->failed()); // already has users
        $this->assertSame(1, DB::connection('pgsql')->table('users')->count(), 'client 1 untouched');
    }

    public function test_extend_suspend_and_archive_from_the_panel(): void
    {
        $this->asOwner();
        $second = Tenant::where('slug', 'wakeel2')->sole();
        $second->update(['subscription_starts_at' => now()->subMonth(), 'subscription_ends_at' => now()->addDays(3), 'status' => 'active']);
        $this->assertSame('expiring', Subscription::state($second->fresh()));

        Livewire::test(ListTenants::class)->callTableAction('extend', $second, ['duration' => '1m'])->assertHasNoTableActionErrors();
        $this->assertSame(now()->addDays(3)->addMonthNoOverflow()->toDateString(), $second->fresh()->subscription_ends_at->toDateString());

        Livewire::test(ListTenants::class)->callTableAction('toggleSuspend', $second);
        $this->assertSame('suspended', Subscription::state($second->fresh()));
        $this->get('http://wakeel2.test/login')->assertOk(); // the address still answers (the stop page comes with the subscriptions)
        $this->asOwner();

        Livewire::test(ListTenants::class)->callTableAction('toggleArchive', $second, ['confirm' => 'wrong'])->assertHasTableActionErrors(['confirm']);
        Livewire::test(ListTenants::class)->callTableAction('toggleArchive', $second, ['confirm' => 'wakeel2']);
        $this->assertSame('archived', $second->fresh()->status);
        $this->get('http://wakeel2.test/login')->assertNotFound();
        $this->assertNotEmpty($this->server()->select("SELECT 1 FROM pg_database WHERE datname = 'subs_test_second'"), 'archiving deletes nothing');
        $this->asOwner();

        Livewire::test(ListTenants::class)->callTableAction('toggleArchive', $second);
        Livewire::test(ListTenants::class)->callTableAction('toggleSuspend', $second);
        $this->assertSame('active', $second->fresh()->status);

        // Client 1 can be neither suspended nor archived.
        $home = Tenant::where('slug', 'main')->sole();
        Livewire::test(ListTenants::class)->assertTableActionHidden('toggleSuspend', $home)->assertTableActionHidden('toggleArchive', $home);
        $this->assertSame(['tenant.extended', 'tenant.suspended', 'tenant.archived', 'tenant.restored', 'tenant.reactivated'],
            DB::connection('platform')->table('platform_audit_logs')->where('tenant_id', $second->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_subscription_dates_and_states(): void
    {
        $start = CarbonImmutable::parse('2027-01-31 00:00', 'Asia/Baghdad');
        $this->assertSame('2027-02-28', Subscription::endFrom($start, '1m')->toDateString());
        $this->assertSame('2028-01-31', Subscription::endFrom($start, '12m')->toDateString());
        $this->assertSame('2027-02-10', Subscription::endFrom($start, 'days', 10)->toDateString());
        $this->assertSame('2027-05-05', Subscription::endFrom($start, 'date', null, '2027-05-05')->toDateString());

        $now = CarbonImmutable::parse('2027-03-01 12:00');
        $t = new Tenant(['status' => 'active', 'is_permanent' => false, 'grace_days' => 2]);
        $t->subscription_ends_at = $now->addDays(10);
        $this->assertSame('active', Subscription::state($t, $now));
        $t->subscription_ends_at = $now->addDays(5);
        $this->assertSame('expiring', Subscription::state($t, $now));
        $t->subscription_ends_at = $now->subDay();
        $this->assertSame('grace', Subscription::state($t, $now));
        $t->subscription_ends_at = $now->subDays(3);
        $this->assertSame('expired', Subscription::state($t, $now));
        // Extending an ended subscription counts from today; a running one from its end.
        $this->assertSame($now->addMonthNoOverflow()->toDateString(), Subscription::extendedEnd($t, '1m', now: $now)->toDateString());
        $t->subscription_ends_at = $now->addDays(5);
        $this->assertSame($now->addDays(5)->addMonthNoOverflow()->toDateString(), Subscription::extendedEnd($t, '1m', now: $now)->toDateString());

        $trial = new Tenant(['status' => 'active', 'is_permanent' => false, 'grace_days' => 0]);
        $trial->trial_ends_at = $now->addDays(3);
        $this->assertSame('trial', Subscription::state($trial, $now));
        $this->assertSame('expired', Subscription::state($trial, $now->addDays(4)));
        $this->assertSame('permanent', Subscription::state(new Tenant(['status' => 'active', 'is_permanent' => true])));
    }

    public function test_the_owner_account_is_made_only_from_the_command_line(): void
    {
        $this->artisan('platform:admin', ['username' => 'omar'])
            ->expectsQuestion('كلمة المرور (12 حرفاً على الأقل)', 'a-very-long-pass')
            ->expectsQuestion('أعد كتابة كلمة المرور', 'a-very-long-pass')
            ->assertSuccessful();
        $this->assertTrue(Auth::guard('platform')->attempt(['username' => 'omar', 'password' => 'a-very-long-pass']));

        $this->artisan('platform:admin', ['username' => 'omar2'])
            ->expectsQuestion('كلمة المرور (12 حرفاً على الأقل)', 'short')
            ->assertFailed();
    }
}

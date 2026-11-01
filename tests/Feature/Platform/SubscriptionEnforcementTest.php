<?php

namespace Tests\Feature\Platform;

use App\Models\Subscriber;
use App\Models\User;
use App\Platform\PlatformSettings;
use App\Platform\Subscription;
use App\Platform\SubscriptionAlerts;
use App\Platform\Tenant;
use App\Platform\TenantAlert;
use App\Platform\TenantManager;
use App\Services\SubscriberService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/** A client whose subscription ended is stopped on the server (and only it), keeps its data and comes back on renewal. */
class SubscriptionEnforcementTest extends BaseTestCase
{
    use BuildsClientDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClients();
        DB::connection('platform')->table('tenant_alerts')->delete();
        $this->second()->update(['status' => 'active', 'is_permanent' => false, 'trial_ends_at' => null, 'grace_days' => 0,
            'subscription_starts_at' => now()->subMonth(), 'subscription_ends_at' => now()->addMonth()]);
    }

    private function second(): Tenant
    {
        return Tenant::where('slug', 'wakeel2')->sole();
    }

    private function signInTo(?Tenant $tenant): User
    {
        app(TenantManager::class)->activate($tenant);
        $admin = User::where('username', 'admin')->sole();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_ended_client_can_only_see_the_end_page_and_sign_out(): void
    {
        app(PlatformSettings::class)->set('contact.phone', '07700000099');
        $this->signInTo($this->second());
        app(SubscriberService::class)->create(['full_name' => 'مشترك محفوظ', 'phone' => '07701119999']);
        $count = Subscriber::count();
        $this->second()->update(['subscription_ends_at' => CarbonImmutable::parse('2026-10-01 00:00', 'Asia/Baghdad')]);

        $this->get('http://wakeel2.test/subscribers')->assertRedirect(route('subscription.ended'));
        $this->get('http://wakeel2.test/subscription-ended')->assertOk()
            ->assertSee('انتهى الاشتراك')->assertSee('2026/10/01')->assertSee('07700000099')->assertSee('تسجيل الخروج');
        $this->post('http://wakeel2.test/livewire/update', [], ['X-Livewire' => 'true'])->assertForbidden();
        $this->postJson('http://wakeel2.test/sync/browser/plan', [])->assertForbidden();
        $this->post('http://wakeel2.test/logout')->assertRedirect();

        // Nothing of its data was touched.
        app(TenantManager::class)->activate($this->second());
        $this->assertSame($count, Subscriber::count());
    }

    public function test_only_that_client_stops_and_a_renewal_brings_it_back(): void
    {
        $this->second()->update(['subscription_ends_at' => now()->subDay()]);

        $this->signInTo(null);
        $this->get('http://localhost/subscribers')->assertOk(); // client 1 works

        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/subscribers')->assertRedirect();

        $tenant = $this->second();
        $tenant->update(['subscription_ends_at' => Subscription::extendedEnd($tenant, '1m')]);
        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/subscribers')->assertOk();
        $this->get('http://wakeel2.test/subscription-ended')->assertRedirect('/'); // nothing to show while it runs
        $this->assertNotSame(403, $this->post('http://wakeel2.test/livewire/update', [], ['X-Livewire' => 'true'])->getStatusCode(), 'the 403 above is the subscription block');
    }

    public function test_a_suspended_client_is_stopped_too_and_grace_days_keep_it_running(): void
    {
        $this->second()->update(['status' => 'suspended']);
        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/')->assertRedirect(route('subscription.ended'));
        $this->get('http://wakeel2.test/subscription-ended')->assertOk()->assertSee('الحساب موقوف');

        $this->second()->update(['status' => 'active', 'subscription_ends_at' => now()->subDay(), 'grace_days' => 3]);
        $this->assertSame('grace', Subscription::state($this->second()));
        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/subscribers')->assertOk()->assertSee('انتهى اشتراكك، وسيتوقف النظام');
    }

    public function test_a_stopped_client_runs_no_scheduled_work(): void
    {
        $this->assertContains('wakeel2', app(TenantManager::class)->runnableSlugs());
        $this->second()->update(['subscription_ends_at' => now()->subDay()]);
        $this->assertNotContains('wakeel2', app(TenantManager::class)->runnableSlugs());
        $this->assertContains('main', app(TenantManager::class)->runnableSlugs());
        $this->second()->update(['subscription_ends_at' => now()->addDay(), 'status' => 'suspended']);
        $this->assertNotContains('wakeel2', app(TenantManager::class)->runnableSlugs());
    }

    public function test_alerts_come_at_7_3_1_and_0_days_each_once(): void
    {
        $ends = CarbonImmutable::parse('2026-12-20 00:00', 'Asia/Baghdad');
        $this->second()->update(['subscription_ends_at' => $ends]);
        $alerts = app(SubscriptionAlerts::class);
        $at = fn (string $day) => CarbonImmutable::parse("{$day} 10:00", 'Asia/Baghdad');

        $this->assertSame(0, $alerts->scan($at('2026-12-10')));         // 10 days left
        $this->assertSame(1, $alerts->scan($at('2026-12-13')));         // 7
        $this->assertSame(0, $alerts->scan($at('2026-12-13')));         // the same run again: nothing
        $this->assertSame(0, $alerts->scan($at('2026-12-15')));         // 5 days: still the 7-day alert
        $this->assertSame(1, $alerts->scan($at('2026-12-17')));         // 3
        $this->assertSame(1, $alerts->scan($at('2026-12-19')));         // 1
        $this->assertSame(1, $alerts->scan($at('2026-12-20')));         // the day itself
        $this->assertSame(0, $alerts->scan($at('2026-12-20')));
        $this->assertSame([7, 3, 1, 0], TenantAlert::where('tenant_id', $this->second()->id)->orderBy('id')->pluck('days_before')->all());

        // A renewal is a new end date: its alerts start again. Client 1 never gets any.
        $this->second()->update(['subscription_ends_at' => $ends->addMonth()]);
        $this->assertSame(1, $alerts->scan($ends->addMonth()->subDays(2)));
        $this->assertSame(3, TenantAlert::where('tenant_id', $this->second()->id)->where('ends_at', $ends->addMonth())->value('days_before'));
        $this->assertFalse(TenantAlert::whereHas('tenant', fn ($q) => $q->where('slug', 'main'))->exists());
    }

    public function test_the_managers_see_the_notice_in_their_panel(): void
    {
        $this->second()->update(['subscription_ends_at' => now()->addDays(3)->endOfDay()]);
        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/subscribers')->assertOk()->assertSee('تنبيه: اشتراكك سينتهي بعد');

        $this->second()->update(['subscription_ends_at' => now()->addMonth()]);
        $this->signInTo($this->second());
        $this->get('http://wakeel2.test/subscribers')->assertDontSee('data-test="subscription-banner"', false);

        $this->signInTo(null);
        $this->get('http://localhost/subscribers')->assertDontSee('data-test="subscription-banner"', false);
    }
}

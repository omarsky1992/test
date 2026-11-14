<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SyncedRenewals\Pages\ListSyncedRenewals;
use App\Filament\Widgets\DetectedShortActivations;
use App\Filament\Widgets\RecentlyActivated;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Models\User;
use App\Services\RenewalService;
use App\Services\SubscriberService;
use App\Sync\CompanyClient;
use App\Sync\CompanySync;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\FakeCompanyClient;
use Tests\TestCase;

/**
 * Every activation a sync sees reaches «المفعّل حديثاً» (new subscribers too), the wallet log tells
 * card / the agent's wallet / the subscriber's own, and a 7-day activation waits for «نعم/لا».
 */
class SyncActivationsTest extends TestCase
{
    /** One subscription as the panel lists it (made-up values). */
    private function sub(string $customer, string $name, string $username, string $id, string $expires, string $status = 'Active'): array
    {
        return [
            'username' => $username, 'status' => ['displayValue' => $status],
            'services' => [['type' => ['displayValue' => 'Base'], 'value' => 'BASIC']],
            'zone' => ['displayValue' => 'FBG0876-2'], 'expires' => $expires,
            'customer' => ['id' => $customer, 'displayValue' => $name], 'self' => ['id' => $id],
        ];
    }

    private function syncLists(array $subs, array $wallet = [])
    {
        $customers = array_map(fn ($s) => ['id' => $s['customer']['id'], 'displayValue' => $s['customer']['displayValue']], $subs);

        return $this->postJson('/sync/browser/run', ['customers' => $customers, 'subscriptions' => $subs]
            + ($wallet ? ['wallet' => ['path' => '/api/transactions', 'items' => $wallet]] : []))->assertOk();
    }

    /** A line of the wallet log, shaped as admin.ftth.iq/api/transactions returns it. */
    private function line(string $type, string $subscription, string $username, string $at, string $ends, ?string $owner = 'Partner', string $payment = 'Wallet', ?string $starts = null): array
    {
        $renew = $type === 'PLAN_RENEW';

        return [
            'id' => random_int(1000000, 99999999), 'type' => $type, 'occuredAt' => $at, 'createdBy' => 'agent',
            'planPrice' => $renew ? 35000 : 0,
            'transactionAmount' => ['value' => $renew ? -35000.0 : 0.0, 'currency' => 'IQD'],
            'remainingBalance' => ['value' => 0.0, 'currency' => 'IQD'],
            'paymentMode' => $renew ? $payment : null,
            'paymentMethod' => $renew ? ['id' => 0, 'entityType' => 'TransactionPaymentMethod', 'displayValue' => $payment, 'description' => null] : null,
            'walletOwnerType' => $renew && $owner ? ['id' => $owner === 'Partner' ? 1 : 0, 'entityType' => 'WalletSource', 'displayValue' => $owner, 'description' => null] : null,
            'subscription' => ['startsAt' => $starts ?? $at, 'endsAt' => $ends, 'type' => 'BASE_PLAN', 'id' => $subscription, 'displayValue' => 'BASIC'],
            'deviceUsername' => $username, 'customer' => ['id' => '1', 'displayValue' => 'مشترك تجريبي'],
        ];
    }

    private function iso(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    public function test_the_wallet_log_tells_the_agents_wallet_from_the_subscribers_own(): void
    {
        $old = '2026-09-01T10:00:00Z';
        $this->syncLists([$this->sub('11', 'علي', 'AAA1', '501', $old, 'Expired'), $this->sub('12', 'حسن', 'BBB1', '502', $old, 'Expired')]);

        $at = CarbonImmutable::now()->subHour();
        $final = $at->addDays(30);
        $response = $this->syncLists([
            $this->sub('11', 'علي', 'AAA1', '501', $this->iso($final)),
            $this->sub('12', 'حسن', 'BBB1', '502', $this->iso($final)),
        ], [
            // The subscriber paid from his own wallet: the site records the renewal to a shorter end
            // and a date change in the same moment that moves it to the real end.
            $this->line('PLAN_RENEW', '501', 'AAA1', $this->iso($at), $this->iso($at->addDays(24)), 'Customer'),
            $this->line('CHANGE_DATE', '501', 'AAA1', $this->iso($at), $this->iso($final), starts: $this->iso($at->addDays(24))),
            // The agent paid from his wallet.
            $this->line('PLAN_RENEW', '502', 'BBB1', $this->iso($at), $this->iso($final), 'Partner'),
        ]);

        $this->assertStringContainsString('محفظة الوكيل 1، محفظة المشترك 1', $response->json('summary'));
        $sources = AccountRenewal::with('account')->get()->mapWithKeys(fn ($r) => [$r->account->username => $r->payment_source])->all();
        $this->assertSame(['AAA1' => 'wallet_customer', 'BBB1' => 'wallet_partner'], $sources);
        $this->assertSame(0, Debt::count());
        Livewire::test(RecentlyActivated::class)->assertSee('📱 محفظة المشترك')->assertSee('👛 محفظة الوكيل');
    }

    public function test_a_new_subscriber_activated_lately_reaches_recently_activated(): void
    {
        $now = CarbonImmutable::now();
        $existing = $this->sub('11', 'علي', 'AAA1', '501', $this->iso($now->addDays(10)));
        // The first import of an empty system marks no one as new.
        $this->syncLists([$existing], [$this->line('PLAN_RENEW', '501', 'AAA1', $this->iso($now->subHour()), $this->iso($now->addDays(10)))]);
        $this->assertSame(0, AccountRenewal::count());

        $this->syncLists([
            $existing,
            // Added on the site and activated 2 hours ago (3 months).
            $this->sub('22', 'زينب جديدة', 'NEW1', '777', $this->iso($now->addDays(90))),
            // Active for a long while, seen for the first time: not an activation of now.
            $this->sub('33', 'قديم', 'OLD1', '888', $this->iso($now->addDays(20))),
            // Active, no line in the wallet log: nothing to tell when it started.
            $this->sub('44', 'بدون سجل', 'NOLOG', '999', $this->iso($now->addDays(25))),
        ], [
            $this->line('PLAN_RENEW', '777', 'NEW1', $this->iso($now->subHours(2)), $this->iso($now->addDays(90)), 'Customer'),
            $this->line('PLAN_RENEW', '888', 'OLD1', $this->iso($now->subDays(10)), $this->iso($now->addDays(20))),
        ]);

        $renewal = AccountRenewal::sole();
        $this->assertSame(['NEW1', 'activated', true, 'wallet_customer'], [$renewal->account->username, $renewal->status, $renewal->is_new, $renewal->payment_source]);
        $this->assertSame(90, $renewal->new_days);
        $this->assertSame(0, Debt::count());
        Livewire::test(RecentlyActivated::class)->assertSee('زينب جديدة')->assertSee('🆕 مشترك جديد');

        // The next sync sees nothing new and records nothing twice.
        $this->syncLists([$existing, $this->sub('22', 'زينب جديدة', 'NEW1', '777', $this->iso($now->addDays(90)))]);
        $this->assertSame(1, AccountRenewal::count());
    }

    public function test_an_account_added_by_hand_and_activated_counts_when_the_site_says_it_started_lately(): void
    {
        $site = new FakeCompanyClient;
        $this->app->instance(CompanyClient::class, $site);
        $fresh = app(SubscriberService::class)->create(['full_name' => 'مضاف يدوياً'], ['username' => 'HAND1'])->accounts()->first();
        app(SubscriberService::class)->create(['full_name' => 'مضاف قديم'], ['username' => 'HAND2']);
        $now = CarbonImmutable::now();

        $site->set(
            ['customer_id' => '51', 'plan' => 'BASIC', 'status' => 'Active', 'username' => 'HAND1', 'subscription_id' => '901',
                'ends_at' => $now->addDays(30)->toIso8601String(), 'starts_at' => $now->subDay()->toIso8601String()],
            ['customer_id' => '52', 'plan' => 'BASIC', 'status' => 'Active', 'username' => 'HAND2', 'subscription_id' => '902',
                'ends_at' => $now->addDays(20)->toIso8601String(), 'starts_at' => $now->subDays(10)->toIso8601String()],
        );
        $run = app(CompanySync::class)->run('manual');

        $renewal = AccountRenewal::sole();
        $this->assertSame([$fresh->id, 'activated', true], [$renewal->account_id, $renewal->status, $renewal->is_new]);
        $this->assertSame(1, $run->stats['new_activations']);
    }

    private function shortActivation(string $name, string $username): AccountRenewal
    {
        $site = new FakeCompanyClient;
        $this->app->instance(CompanyClient::class, $site);
        $record = ['customer_id' => 'c'.$username, 'name' => $name, 'plan' => 'BASIC', 'username' => $username, 'subscription_id' => 's'.$username];
        $site->set(['status' => 'Expired', 'ends_at' => now()->subDays(3)->toIso8601String()] + $record);
        app(CompanySync::class)->run('manual');
        $site->set(['status' => 'Active', 'ends_at' => now()->addDays(7)->toIso8601String()] + $record);
        app(CompanySync::class)->run('manual');

        return AccountRenewal::whereHas('account', fn ($q) => $q->where('username', $username))->sole();
    }

    public function test_a_seven_day_activation_waits_for_yes_or_no(): void
    {
        $yes = $this->shortActivation('زيد نعم', 'YES1');
        $no = $this->shortActivation('عمر لا', 'NO1');
        $this->assertSame(['pending_debt', 35000, null], [$yes->status, $yes->amount, $yes->debt_id]);
        $this->assertSame(0, Debt::count());

        Livewire::test(DetectedShortActivations::class)->assertSee('زيد نعم')->assertSee('عمر لا')
            ->callTableAction('yes', $yes, data: ['amount' => 30000])->assertHasNoTableActionErrors()->assertNotified();
        $debt = Debt::sole();
        $this->assertSame([DebtBucket::Secondary, 30000, $yes->account_id], [$debt->bucket, $debt->balance, $debt->account_id]);
        $this->assertSame(['debt_created', $debt->id, $this->admin->id], [$yes->fresh()->status, $yes->fresh()->debt_id, $yes->fresh()->decided_by]);

        Livewire::test(DetectedShortActivations::class)->assertDontSee('زيد نعم')
            ->callTableAction('no', $no)->assertHasNoTableActionErrors();
        $this->assertSame('no_debt', $no->fresh()->status);
        $this->assertSame(1, Debt::count());

        // Each is decided once.
        $this->expectException(BusinessRuleException::class);
        app(RenewalService::class)->approveSecondary($no->fresh());
    }

    public function test_only_who_decides_activations_can_answer_and_see_the_sync_log(): void
    {
        $renewal = $this->shortActivation('زيد', 'EMP1');
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->get('/sync-log')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(RenewalService::class)->declineSecondary($renewal);
    }

    public function test_the_sync_log_shows_every_activation_with_how_it_was_paid_and_what_became_of_it(): void
    {
        $yes = $this->shortActivation('زيد نعم', 'YES1');
        $no = $this->shortActivation('عمر لا', 'NO1');
        app(RenewalService::class)->approveSecondary($yes);
        app(RenewalService::class)->declineSecondary($no);
        $no->update(['payment_source' => 'wallet_customer']);

        $this->get('/sync-log')->assertOk()->assertSee('زيد نعم')->assertSee('عمر لا')
            ->assertSee('دين ثانوي')->assertSee('7 أيام: بدون دين (لا)')->assertSee('📱 محفظة المشترك');
        Livewire::test(ListSyncedRenewals::class)
            ->filterTable('outcome', 'no_debt')->assertCanSeeTableRecords([$no])->assertCanNotSeeTableRecords([$yes]);
        Livewire::test(ListSyncedRenewals::class)
            ->filterTable('payment_source', 'wallet_customer')->assertCanSeeTableRecords([$no])->assertCanNotSeeTableRecords([$yes]);
        $this->assertSame(2, Account::count());
    }
}

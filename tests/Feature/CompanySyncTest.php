<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\CompanySyncPage;
use App\Filament\Pages\EmployeeHome;
use App\Filament\Widgets\RecentlyActivated;
use App\Imports\SpreadsheetReader;
use App\Imports\SubscriberImporter;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\DebtTransfer;
use App\Models\FinancialTransaction;
use App\Models\Subscriber;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WhatsappOutbox;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\RecentActivationService;
use App\Services\RenewalService;
use App\Services\Settings;
use App\Support\SubscriberStatus;
use App\Sync\CompanyClient;
use App\Sync\CompanySync;
use App\Sync\CompanySyncException;
use App\Sync\MissedRenewals;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeCompanyClient;
use Tests\TestCase;

class CompanySyncTest extends TestCase
{
    private FakeCompanyClient $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new FakeCompanyClient;
        $this->app->instance(CompanyClient::class, $this->site);
    }

    private function sync(): SyncRun
    {
        return app(CompanySync::class)->run('manual');
    }

    private function siteRecord(array $overrides = []): array
    {
        return [
            'customer_id' => '2825350', 'name' => 'أحمد كريم', 'phone' => '7701234567', 'plan' => 'BASIC', 'status' => 'Active',
            'ends_at' => '2026-10-15T10:00:00Z', 'zone' => 'FBG0876-2', 'gps' => '33.31,44.36', 'username' => 'FBG876F33P08',
            'serial' => 'tdtc35980df8', 'fat' => 'FAT33', 'port' => '3', 'subscription_id' => '13525583', ...$overrides,
        ];
    }

    /** Days from the test clock (2026-09-21 11:00 Baghdad) to an end date. */
    private function endsIn(int $days): string
    {
        return CarbonImmutable::now()->addDays($days)->addHours(2)->toIso8601String();
    }

    public function test_a_new_subscriber_on_the_site_is_created_with_all_fields(): void
    {
        $this->site->set($this->siteRecord());

        $run = $this->sync();

        $this->assertSame('success', $run->status);
        $subscriber = Subscriber::where('external_id', '2825350')->sole();
        $this->assertSame('أحمد كريم', $subscriber->full_name);
        $this->assertSame('07701234567', $subscriber->phone);
        $account = $subscriber->accounts()->sole();
        $this->assertSame('FBG876F33P08', $account->username);
        $this->assertSame('TDTC35980DF8', $account->serial_number);
        $this->assertSame('FAT33', $account->fat_code);
        $this->assertSame('3', $account->port_number);
        $this->assertSame('FBG0876-2', $account->zone_code);
        $this->assertSame('33.31,44.36', $account->gps);
        $this->assertSame('13525583', $account->external_subscription_id);
        $this->assertSame('BASIC', $account->external_plan);
        $this->assertSame($this->plan('basic')->id, $account->current_plan_id);
        $this->assertSame('active', $account->external_status);
        $this->assertTrue($account->external_ends_at->equalTo(CarbonImmutable::parse('2026-10-15T10:00:00Z')));
        $this->assertSame(24, $account->company_days_left);
        $this->assertSame(1, $run->stats['subscribers_created']);
        // A first sighting is not a renewal.
        $this->assertSame(0, Debt::count());
    }

    public function test_the_same_subscriber_is_never_created_twice(): void
    {
        $this->site->set($this->siteRecord(), $this->siteRecord(['username' => 'FBG876F33P09', 'subscription_id' => '13525584', 'serial' => 'X2']));

        $this->sync();
        $this->sync();

        $this->assertSame(1, Subscriber::count());
        $this->assertSame(2, Account::count());
    }

    public function test_phone_is_optional_and_a_manual_phone_is_kept(): void
    {
        $this->site->set($this->siteRecord(['phone' => null]));
        $this->sync();
        $subscriber = Subscriber::sole();
        $this->assertNull($subscriber->phone);

        $subscriber->update(['phone' => '07801112222', 'phone_normalized' => '9647801112222']);
        $this->site->set($this->siteRecord(['phone' => '7709998888']));
        $this->sync();

        $this->assertSame('07801112222', $subscriber->fresh()->phone);
    }

    public function test_an_old_excel_import_is_updated_from_the_site_without_touching_history(): void
    {
        // Excel exported long ago: expired, 0 days.
        $path = tempnam(sys_get_temp_dir(), 'old').'.csv';
        file_put_contents($path, "معرف المشترك,الاسم,رقم الهاتف,اسم الاشتراك,الحالة,تاريخ الانتهاء,اسم الجهاز,معرف الاشتراك\n2825350,أحمد كريم,7701234567,BASIC,Expired,2026-08-01,FBG876F33P08,13525583\n");
        $importer = app(SubscriberImporter::class);
        $mapping = $importer->suggestMapping(app(SpreadsheetReader::class)->read($path, 'old.csv')['headers']);
        $importer->execute($path, 'old.csv', $mapping);
        @unlink($path);

        $account = Account::sole();
        $this->assertSame(0, $account->company_days_left);
        $txnsBefore = FinancialTransaction::count();
        $auditBefore = AuditLog::count();

        // Activated on the site since: 5 days left now.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(5)]));
        $this->sync();

        $account->refresh();
        $this->assertSame(5, $account->company_days_left);
        $this->assertSame('active', $account->external_status);
        $this->assertNotNull($account->company_synced_at);
        $this->assertSame(1, Subscriber::count());
        // Earlier transactions and audit lines are all still there.
        $this->assertGreaterThanOrEqual($txnsBefore, FinancialTransaction::count());
        $this->assertGreaterThan($auditBefore, AuditLog::count());
    }

    public static function renewalDays(): array
    {
        return ['0 → 4' => [4], '0 → 5' => [5], '0 → 6' => [6], '0 → 7' => [7]];
    }

    #[DataProvider('renewalDays')]
    public function test_zero_to_positive_days_is_a_renewal_with_one_secondary_debt(int $days): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->assertSame(0, Account::sole()->company_days_left);

        $this->travelToTime('2026-09-22 09:30:00');
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn($days)]));
        $run = $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame(0, $renewal->previous_days);
        $this->assertSame($days, $renewal->new_days);
        $this->assertSame('BASIC', $renewal->plan_name);
        $this->assertSame(35000, $renewal->amount);
        $this->assertTrue($renewal->detected_at->equalTo(CarbonImmutable::parse('2026-09-22 09:30:00')));

        $debt = Debt::sole();
        $this->assertSame(DebtSource::Renewal, $debt->source);
        $this->assertSame(DebtBucket::Secondary, $debt->bucket);
        $this->assertSame(35000, $debt->balance);
        $this->assertSame($debt->id, $renewal->debt_id);
        $this->assertSame(1, $run->stats['debts_created']);

        // Secondary only: primary debts, the cash box and the company balance are unchanged.
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame(0, $this->balanceOf($this->cash()->ledger_account_id));
        $this->assertSame(0, $this->balanceOf($this->companyBox()->ledger_account_id));
        $this->assertTrue(AuditLog::where('action', 'renewal.detected')->exists());
    }

    public function test_scanning_again_never_repeats_the_debt(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();

        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(5)]));
        $this->sync();                         // 0 → 5: debt
        $this->sync();                         // 5 → 5: nothing
        $this->travelToTime('2026-09-22 12:00:00');
        $this->sync();                         // 5 → 4: nothing

        $this->assertSame(1, Debt::count());
        $this->assertSame(1, AccountRenewal::count());
        $this->assertSame(4, Account::sole()->company_days_left);
    }

    public function test_expiry_then_a_new_renewal_creates_a_new_debt(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        $firstEnd = $this->endsIn(7);
        $this->site->set($this->siteRecord(['ends_at' => $firstEnd]));
        $this->sync();                         // 0 → 7

        $this->travelToTime('2026-09-29 12:00:00');
        $this->sync();                         // the 7 days ran out: 0
        $this->assertSame(0, Account::sole()->company_days_left);

        $this->travelToTime('2026-09-30 10:00:00');
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(7)]));
        $this->sync();                         // 0 → 7 again

        $this->assertSame(2, Debt::where('source', DebtSource::Renewal)->count());
        $this->assertSame(2, AccountRenewal::count());
        $this->assertSame(70000, $this->balanceOf(Ledger::AR_SECONDARY));
    }

    public function test_the_same_renewal_seen_twice_after_a_lost_update_still_gives_one_debt(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        $end = $this->endsIn(5);
        $this->site->set($this->siteRecord(['ends_at' => $end]));
        $this->sync();

        // Something reset the stored days to 0 (e.g. an old file); the renewal reference stops a duplicate.
        Account::query()->update(['company_days_left' => 0]);
        $this->sync();

        $this->assertSame(1, Debt::count());
    }

    public function test_an_unknown_plan_records_the_renewal_without_a_debt(): void
    {
        $this->site->set($this->siteRecord(['plan' => 'Fiber 35', 'ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        $this->site->set($this->siteRecord(['plan' => 'Fiber 35', 'ends_at' => $this->endsIn(6)]));
        $run = $this->sync();

        $this->assertSame('no_price', AccountRenewal::sole()->status);
        $this->assertSame(0, Debt::count());
        $this->assertSame(1, $run->stats['renewals_without_price']);
    }

    public function test_a_failing_site_marks_the_run_failed_and_changes_nothing(): void
    {
        $this->app->instance(CompanyClient::class, new class implements CompanyClient
        {
            public function records(array $options = []): iterable
            {
                throw new CompanySyncException('تعذّر الاتصال بموقع الشركة');
            }
        });

        $run = $this->sync();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('تعذّر', $run->error);
        $this->assertSame(0, Subscriber::count());
    }

    public function test_a_subscription_that_left_the_active_list_and_came_back_renewed_is_a_renewal(): void
    {
        // Last seen with 2 days left; the Active list then stops showing it while it is expired.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(2)]));
        $this->sync();
        $this->assertSame(2, Account::sole()->company_days_left);

        $this->travelToTime('2026-09-26 10:00:00');
        $this->site->set();
        $this->sync();

        // Renewed: back in the list with 7 days. Its saved end date has passed, so it was at 0.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(7)]));
        $this->sync();
        $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame(0, $renewal->previous_days);
        $this->assertSame(7, $renewal->new_days);
        $this->assertSame(1, Debt::count());
    }

    public function test_a_full_activation_before_the_end_waits_in_recently_activated_with_no_debt(): void
    {
        // 3 days left, then a full activation added on top: 33 days.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(3)]));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(33)]));
        $run = $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame(['activated', 3, 33], [$renewal->status, $renewal->previous_days, $renewal->new_days]);
        $this->assertSame(0, Debt::count());
        $this->assertSame(1, $run->stats['activations']);
        $this->assertSame([$renewal->id], RecentActivationService::pending()->pluck('id')->all());

        // Seen again: nothing doubles.
        $this->sync();
        $this->assertSame(1, AccountRenewal::count());
    }

    public function test_a_small_date_change_before_the_end_is_not_a_renewal(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(3)]));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(6)]));
        $this->sync();

        $this->assertSame(0, AccountRenewal::count());
    }

    public function test_a_missed_full_activation_before_the_end_is_recovered_into_recently_activated(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(3)]));
        $this->sync();
        // Applied by a sync before the early rule: only the audit log knows.
        $this->app->make(Settings::class)->set('activation.partial_days', 40);
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(33)]));
        $this->sync();
        $this->app->make(Settings::class)->set('activation.partial_days', 7);
        $this->assertSame(0, AccountRenewal::count());

        $this->assertSame(1, count(app(MissedRenewals::class)->find()));
        app(MissedRenewals::class)->record();

        $renewal = AccountRenewal::sole();
        $this->assertSame('activated', $renewal->status);
        $this->assertNotNull($renewal->sync_run_id);
        $this->assertSame(1, RecentActivationService::pending()->count());
        $this->assertSame(0, Debt::count());
    }

    public function test_renewals_a_sync_applied_without_recording_are_recovered_once_from_the_audit_log(): void
    {
        // As on the live system: accounts imported before days-left existed, then synced by code that
        // treated an empty days-left as a first sighting, so the new dates were saved with no renewal.
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        Account::query()->update(['company_days_left' => null]);
        $renewed = $this->siteRecord(['ends_at' => $this->endsIn(7)]);
        // Simulate the old behaviour: dates updated, nothing recorded.
        $account = Account::sole();
        $this->site->set($renewed);
        DB::table('account_renewals')->delete();
        $this->sync();
        AccountRenewal::query()->delete();
        Debt::query()->update(['status' => 'voided', 'void_reason' => 'test']);
        $this->assertTrue(AuditLog::where('action', 'account.synced')->where('entity_id', $account->id)->exists());

        $missed = app(MissedRenewals::class);
        $this->assertCount(1, $missed->find());
        $this->assertSame(35000, $missed->find()[0]['amount']);

        $result = $missed->record();
        $this->assertSame(['renewals' => 1, 'debts' => 1, 'amount' => 35000], $result);
        $this->assertSame([], $missed->find());
        $this->assertSame(['renewals' => 0, 'debts' => 0, 'amount' => 0], $missed->record());
    }

    public function test_the_recovery_button_creates_the_missing_debts(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(6)]));
        $this->sync();
        AccountRenewal::query()->delete();

        Livewire::test(CompanySyncPage::class)
            ->callAction('missedRenewals')
            ->assertNotified('سُجّل 1 تجديد و1 دين ثانوي');
        $this->assertSame(1, AccountRenewal::count());
    }

    public function test_more_than_seven_days_is_a_full_activation_with_no_debt(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $run = $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame('activated', $renewal->status);
        $this->assertSame(30, $renewal->new_days);
        $this->assertNull($renewal->debt_id);
        $this->assertSame(0, Debt::count());
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(1, $run->stats['activations']);
        $this->assertSame(0, $run->stats['debts_created']);
    }

    public function test_a_debt_recorded_earlier_for_a_full_activation_is_voided(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        // As before the rule: a 30-day renewal recorded with a debt.
        $this->app->make(Settings::class)->set('activation.partial_days', 40);
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $this->sync();
        $this->assertSame(1, Debt::where('status', 'open')->count());
        $this->app->make(Settings::class)->set('activation.partial_days', 7);

        $result = app(RenewalService::class)->reclassifyFullActivations();

        $this->assertSame(['fixed' => 1, 'kept_paid' => 0], $result);
        $this->assertSame('activated', AccountRenewal::sole()->status);
        $this->assertSame('voided', Debt::sole()->status->value);
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
    }

    public static function selfTestDays(): array
    {
        return ['0 → 1' => [1], '0 → 2' => [2], '0 → 3' => [3]];
    }

    #[DataProvider('selfTestDays')]
    public function test_a_test_the_subscriber_gave_themselves_is_recorded_with_no_debt(int $days): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn($days)]));
        $run = $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame('test', $renewal->status);
        $this->assertNull($renewal->amount);
        $this->assertNull($renewal->debt_id);
        $this->assertSame(0, Debt::count());
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(1, $run->stats['self_tests']);
        $this->assertSame(0, $run->stats['debts_created']);
        $this->assertSame(0, WhatsappOutbox::count());
    }

    public function test_a_real_short_activation_after_the_test_ran_out_is_a_secondary_debt(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(2)]));
        $this->sync();

        // The test ends: days back to 0, then the agent activates 7 days.
        $this->travel(3)->days();
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(7)]));
        $this->sync();

        $this->assertSame(['test', 'debt_created'], AccountRenewal::orderBy('id')->pluck('status')->all());
        $this->assertSame(35000, Debt::sole()->balance);
    }

    public function test_a_debt_a_sync_recorded_earlier_for_a_self_test_is_voided(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z']));
        $this->sync();
        // As before the rule: a 2-day test recorded with a debt.
        $this->app->make(Settings::class)->set('activation.min_partial_days', 1);
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(2)]));
        $this->sync();
        $this->assertSame(1, Debt::where('status', 'open')->count());
        $this->app->make(Settings::class)->set('activation.min_partial_days', 4);

        $result = app(RenewalService::class)->reclassifySelfTests();

        $this->assertSame(['fixed' => 1, 'kept_paid' => 0], $result);
        $this->assertSame('test', AccountRenewal::sole()->status);
        $this->assertSame('voided', Debt::sole()->status->value);
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
    }

    public function test_a_late_sync_still_turns_a_short_activation_into_a_secondary_debt(): void
    {
        // The last sync saw one day left; the next one comes two days later and sees 4 days.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(1)]));
        $this->sync();
        $this->assertSame(1, Account::sole()->company_days_left);

        $this->travel(2)->days();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(4)]));
        $this->sync();

        $this->assertSame('debt_created', AccountRenewal::sole()->status);
        $this->assertSame(35000, Debt::sole()->balance);
    }

    public function test_a_seven_day_activation_seen_late_with_few_days_left_is_not_a_self_test(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(1)]));
        $this->sync();
        $oldEnd = CarbonImmutable::parse($this->endsIn(1));

        // Activated 7 days right at the old end; the next sync comes 5 days later and sees 2 days left.
        $this->travel(6)->days();
        $this->site->set($this->siteRecord(['ends_at' => $oldEnd->addDays(7)->toIso8601String()]));
        $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame(2, $renewal->new_days);
        $this->assertSame('debt_created', $renewal->status);
        $this->assertSame(1, Debt::count());
    }

    public function test_a_self_test_seen_late_is_still_a_self_test(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(1)]));
        $this->sync();
        $oldEnd = CarbonImmutable::parse($this->endsIn(1));

        // A 2-day test from the old end, seen a day later.
        $this->travel(2)->days();
        $this->site->set($this->siteRecord(['ends_at' => $oldEnd->addDays(2)->toIso8601String()]));
        $this->sync();

        $this->assertSame('test', AccountRenewal::sole()->status);
        $this->assertSame(0, Debt::count());
    }

    private function fullActivationFromSync(): AccountRenewal
    {
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $this->sync();

        return AccountRenewal::sole();
    }

    public function test_a_thirty_day_activation_from_sync_waits_in_recently_activated_and_debt_adds_a_primary_debt(): void
    {
        $renewal = $this->fullActivationFromSync();
        $this->assertSame(0, Debt::count());

        Livewire::test(RecentlyActivated::class)
            ->assertCanSeeTableRecords([$renewal])->assertSee('أحمد كريم')
            ->callTableAction('debt', $renewal, data: ['amount' => 35000])
            ->assertHasNoTableActionErrors()
            ->assertCountTableRecords(0);

        $debt = Debt::sole();
        $this->assertSame(DebtBucket::Primary, $debt->bucket);
        $this->assertSame(35000, $debt->balance);
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $renewal->refresh();
        $this->assertSame('debt', $renewal->decision);
        $this->assertSame($debt->id, $renewal->decision_debt_id);
        $this->assertSame($this->admin->id, $renewal->decided_by);
        // «فعّال وعليه دين», not «منتهي وعليه دين».
        $ids = fn (string $f) => SubscriberStatus::apply(SubscriberStatus::base(), $f)->pluck('accounts.id')->all();
        $this->assertSame([Account::sole()->id], $ids('active_primary'));
        $this->assertSame([], $ids('expired_primary'));

        // Decided once: a second click changes nothing.
        $this->expectException(BusinessRuleException::class);
        app(RecentActivationService::class)->asDebt($renewal);
    }

    public function test_cash_keeps_the_recent_activation_active_with_no_debt(): void
    {
        $renewal = $this->fullActivationFromSync();

        Livewire::test(RecentlyActivated::class)
            ->callTableAction('cash', $renewal)
            ->assertCountTableRecords(0);

        $this->assertSame('cash', $renewal->fresh()->decision);
        $this->assertSame(0, Debt::count());
        $this->assertSame(0, $this->balanceOf(Ledger::AR_PRIMARY));
        $ids = fn (string $f) => SubscriberStatus::apply(SubscriberStatus::base(), $f)->pluck('accounts.id')->all();
        $this->assertSame([Account::sole()->id], $ids('active'));
        $this->assertSame([], $ids('active_primary'));
        $this->assertTrue(AuditLog::where('action', 'renewal.decided')->exists());
    }

    public function test_only_full_activations_from_sync_are_recently_activated_and_only_with_the_permission(): void
    {
        // A short activation (secondary debt) is not on the list.
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(7)]));
        $this->sync();
        $this->assertSame(0, RecentActivationService::pending()->count());

        $renewal = AccountRenewal::sole();
        $this->expectException(BusinessRuleException::class);
        app(RecentActivationService::class)->asCash($renewal);
    }

    public function test_an_employee_needs_the_permission_for_recently_activated(): void
    {
        $renewal = $this->fullActivationFromSync();
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->assertFalse(RecentlyActivated::canView());
        try {
            app(RecentActivationService::class)->asCash($renewal);
            $this->fail('An employee without the permission decided a recent activation.');
        } catch (AuthorizationException) {
        }
        $this->assertNull($renewal->fresh()->decision);

        $employee->givePermissionTo('activations.recent');
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('المفعّل حديثاً');
        app(RecentActivationService::class)->asCash($renewal);
        $this->assertSame('cash', $renewal->fresh()->decision);
    }

    public function test_transfer_moves_the_unpaid_secondary_debt_of_a_recent_activation_to_primary(): void
    {
        // A 7-day activation left a secondary debt; later a 30-day activation with nothing paid.
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(7)]));
        $this->sync();
        $this->travel(8)->days();
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $this->sync();
        $secondary = Debt::sole();
        $renewal = RecentActivationService::pending()->sole();

        Livewire::test(RecentlyActivated::class)
            ->assertSee('35,000')
            ->assertTableActionVisible('transfer', $renewal)
            ->callTableAction('transfer', $renewal)
            ->assertHasNoTableActionErrors()
            ->assertCountTableRecords(0);

        $this->assertSame(DebtBucket::Primary, $secondary->fresh()->bucket);
        $this->assertSame(1, Debt::count());
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame('transfer', $renewal->fresh()->decision);
        $this->assertSame(1, DebtTransfer::count());
    }

    public function test_transfer_is_offered_only_with_an_unpaid_secondary_debt(): void
    {
        $renewal = $this->fullActivationFromSync();

        Livewire::test(RecentlyActivated::class)
            ->assertTableActionHidden('transfer', $renewal);
        $this->expectException(BusinessRuleException::class);
        app(RecentActivationService::class)->asTransfer($renewal);
    }

    public function test_money_already_taken_from_a_recent_activation_is_shown_beside_it(): void
    {
        // Another subscriber activated too, with nothing received.
        $this->site->set($this->siteRecord(['customer_id' => '9', 'subscription_id' => '9', 'username' => 'OTHER1', 'name' => 'حسن علي', 'ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['customer_id' => '9', 'subscription_id' => '9', 'username' => 'OTHER1', 'name' => 'حسن علي', 'ends_at' => $this->endsIn(30)]));
        $this->sync();
        $this->travel(1)->hours();
        $this->site->set($this->siteRecord(['ends_at' => '2026-09-01T10:00:00Z', 'status' => 'Expired']));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $this->sync();
        $renewal = RecentActivationService::pending()->where('account_id', '!=', AccountRenewal::orderBy('id')->first()->account_id)->sole();

        // An employee took the money in cash.
        $employee = User::factory()->create(['name' => 'كرار', 'branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        app(PaymentService::class)->record(Account::find($renewal->account_id), 35000, PaymentMethod::Cash, type: PaymentType::Advance);
        $this->actingAs($this->admin);

        Livewire::test(RecentlyActivated::class)
            ->assertCountTableRecords(2)
            ->assertSeeInOrder(['أحمد كريم', '35,000', 'كرار', 'حسن علي'])
            ->callTableAction('cash', $renewal)
            ->assertCountTableRecords(1);
        $this->assertSame('cash', $renewal->fresh()->decision);

        // Money from long before the activation is not counted.
        $this->assertSame(1, RecentActivationService::receipts($renewal)->count());
    }
}

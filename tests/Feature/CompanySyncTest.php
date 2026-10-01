<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Imports\SpreadsheetReader;
use App\Imports\SubscriberImporter;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\Subscriber;
use App\Services\Ledger;
use App\Sync\CompanyClient;
use App\Sync\CompanySync;
use Carbon\CarbonImmutable;
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

    private function sync(): \App\Models\SyncRun
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
        return ['0 → 5' => [5], '0 → 6' => [6], '0 → 7' => [7]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renewalDays')]
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
        $this->app->instance(CompanyClient::class, new class implements CompanyClient {
            public function records(array $options = []): iterable
            {
                throw new \App\Sync\CompanySyncException('تعذّر الاتصال بموقع الشركة');
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

        // Renewed: back in the list with 30 days. Its saved end date has passed, so it was at 0.
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(30)]));
        $this->sync();
        $this->sync();

        $renewal = AccountRenewal::sole();
        $this->assertSame(0, $renewal->previous_days);
        $this->assertSame(30, $renewal->new_days);
        $this->assertSame(1, Debt::count());
    }

    public function test_a_renewal_before_the_end_is_still_not_counted(): void
    {
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(3)]));
        $this->sync();
        $this->site->set($this->siteRecord(['ends_at' => $this->endsIn(33)]));
        $this->sync();

        $this->assertSame(0, AccountRenewal::count());
    }
}

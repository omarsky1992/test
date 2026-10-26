<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySyncPage;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\SubscriberCards\Pages\ListSubscriberCards;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Widgets\RecentlyActivated;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\Subscriber;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\OpticalSignal;
use App\Sync\BrowserScripts;
use App\Sync\OntReadings;
use App\Sync\WalletTransactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The browser button posts what the company panel returned (its own JSON) to these endpoints.
 */
class BrowserSyncTest extends TestCase
{
    private function lists(string $expires = '2026-10-15T10:00:00Z', string $status = 'Active'): array
    {
        return [
            'customers' => [['id' => '2825350', 'displayValue' => 'أحمد كريم', 'primaryPhone' => '07701234567']],
            'subscriptions' => [[
                'username' => 'FBG876F33P08',
                'status' => ['displayValue' => $status],
                'services' => [['type' => ['displayValue' => 'Base'], 'value' => 'BASIC']],
                'zone' => ['displayValue' => 'FBG0876-2'],
                'expires' => $expires,
                'customer' => ['id' => '2825350', 'displayValue' => 'أحمد كريم'],
                'self' => ['id' => '13525583'],
            ]],
        ];
    }

    private function details(): array
    {
        return ['2825350' => [
            'customer' => ['primaryContact' => ['mobile' => '07701234567'], 'addresses' => [['gpsCoordinate' => ['latitude' => 33.31, 'longitude' => 44.36]]]],
            'subscriptions' => [['self' => ['id' => '13525583'], 'deviceDetails' => ['username' => 'FBG876F33P08', 'serial' => 'TDTC35980DF8', 'fat' => ['portNumber' => 3, 'displayValue' => 'FAT33']]]],
        ]];
    }

    public function test_the_plan_asks_for_details_of_new_customers_only_once_a_week(): void
    {
        $this->postJson('/sync/browser/plan', $this->lists())->assertOk()->assertJson(['needs' => ['2825350'], 'subscriptions' => 1]);

        $this->postJson('/sync/browser/run', [...$this->lists(), 'details' => $this->details()])->assertOk()->assertJson(['status' => 'success']);
        $this->assertTrue(Cache::has('sync.details_checked.2825350'));

        // Complete now: nothing more to fetch.
        $this->postJson('/sync/browser/plan', $this->lists())->assertOk()->assertJson(['needs' => []]);
    }

    public function test_a_browser_run_creates_the_subscriber_with_all_fields(): void
    {
        $response = $this->postJson('/sync/browser/run', [...$this->lists(), 'details' => $this->details()]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertStringContainsString('جديد 1 مشترك', $response->json('summary'));
        $account = Account::sole();
        $this->assertSame('FBG876F33P08', $account->username);
        $this->assertSame('TDTC35980DF8', $account->serial_number);
        $this->assertSame('FAT33', $account->fat_code);
        $this->assertSame('3', $account->port_number);
        $this->assertSame('33.31,44.36', $account->gps);
        $this->assertSame('07701234567', Subscriber::sole()->phone);
        $this->assertSame('browser', SyncRun::sole()->trigger);
    }

    public function test_a_renewal_seen_by_the_browser_creates_one_secondary_debt(): void
    {
        $this->postJson('/sync/browser/run', $this->lists('2026-09-01T10:00:00Z', 'Expired'))->assertOk();
        $renewed = $this->lists(CarbonImmutable::now()->addDays(7)->addHour()->toIso8601String());

        $this->postJson('/sync/browser/run', $renewed)->assertOk();
        $this->postJson('/sync/browser/run', $renewed)->assertOk();

        $this->assertSame(1, AccountRenewal::count());
        $this->assertSame(1, Debt::count());
    }

    public function test_an_empty_list_is_refused_with_a_clear_message(): void
    {
        $this->postJson('/sync/browser/run', ['customers' => [], 'subscriptions' => []])
            ->assertUnprocessable()->assertJsonPath('message', 'لم يصل أي اشتراك من موقع الشركة.');
        $this->assertSame(0, SyncRun::count());
    }

    public function test_only_users_allowed_to_sync_can_use_it(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->get('/sync/browser')->assertForbidden();
        $this->postJson('/sync/browser/run', $this->lists())->assertForbidden();
    }

    public function test_the_receive_window_and_the_button_are_served(): void
    {
        $this->get('/sync/browser')->assertOk()->assertSee('https://admin.ftth.iq', false);
        $this->get('/company-sync')->assertOk()->assertSee('مزامنة المشتركين')->assertSee('javascript:', false);

        auth()->logout();
        $this->get('/sync/browser')->assertRedirect('/login');
    }

    public function test_the_chrome_extension_is_built_for_this_system(): void
    {
        $response = $this->get('/sync/extension.zip');

        $response->assertOk()->assertDownload('subs-sync-extension.zip');
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $manifest = json_decode($zip->getFromName('subs-sync/manifest.json'), true);
        $script = $zip->getFromName('subs-sync/content.js');

        $this->assertSame(3, $manifest['manifest_version']);
        $this->assertSame(['https://admin.ftth.iq/*'], $manifest['content_scripts'][0]['matches']);
        $this->assertStringContainsString('const SUBS_APP = "'.request()->getSchemeAndHttpHost().'"', $script);
        $this->assertStringContainsString('subs-sync', $script);
        $this->assertStringNotContainsString("method: 'POST'", $script);
    }

    /** Lines of the company wallet log, as admin.ftth.iq/api/transactions returns them (made-up values). */
    private function walletLine(string $payment, string $ends, string $type = 'BASE_PLAN', array $extra = []): array
    {
        $method = ['id' => $payment === 'Card' ? 1 : 0, 'entityType' => 'TransactionPaymentMethod', 'displayValue' => $payment, 'description' => null];

        return [
            'walletTransferDetails' => null, 'salesType' => ['id' => 0, 'entityType' => 'SalesType', 'displayValue' => 'Upfront', 'description' => null],
            'changeType' => null, 'walletType' => ['id' => 0, 'entityType' => 'WalletType', 'displayValue' => 'Main', 'description' => null],
            'createdBy' => 'agent', 'planPrice' => 35000, 'id' => 108376600 + random_int(1, 999), 'type' => 'PLAN_RENEW',
            'occuredAt' => '2026-09-21T10:00:00Z', 'discountAmount' => 0, 'discountType' => '', 'serialNumber' => '', 'zoneId' => 'FBG0876-2',
            'customer' => ['id' => '2825350', 'displayValue' => 'أحمد كريم'], 'partner' => ['id' => '2342999', 'displayValue' => 'وكيل تجريبي'],
            'deviceUsername' => 'FBG876F33P08', 'paymentMode' => $payment, 'paymentMethod' => $method,
            'transactionAmount' => ['value' => -35000, 'currency' => 'IQD'], 'remainingBalance' => ['value' => 100000, 'currency' => 'IQD'],
            'subscription' => ['id' => '13525583', 'displayValue' => 'Basic', 'type' => $type, 'startsAt' => '2026-09-21T10:00:00Z', 'endsAt' => $ends],
            ...$extra,
        ];
    }

    public function test_a_wallet_line_finds_its_account_by_its_subscription_only(): void
    {
        $line = WalletTransactions::parse($this->walletLine('Card', '2026-10-21T10:00:00Z'));
        $this->assertSame(['card', ['13525583', 'FBG876F33P08'], 35000], [$line['source'], $line['values'], $line['amount']]);
        $this->assertSame('2026-10-21', $line['ends']->toDateString());

        // A date change has no payment type; a value-added service is not the renewal.
        $this->assertNull(WalletTransactions::parse(['id' => 1, 'type' => 'CHANGE_DATE', 'paymentMode' => null, 'subscription' => ['id' => '13525583', 'endsAt' => '2026-10-21T10:00:00Z']]));
        $this->assertNull(WalletTransactions::parse($this->walletLine('Wallet', '2026-10-21T10:00:00Z', 'VAS')));
    }

    public function test_the_wallet_log_tells_whether_each_renewal_was_paid_by_card_or_wallet(): void
    {
        $this->postJson('/sync/browser/run', $this->lists('2026-09-01T10:00:00Z', 'Expired'))->assertOk();
        $ends = CarbonImmutable::now()->addDays(30)->addHour()->toIso8601String();
        $wallet = ['path' => '/api/transactions', 'items' => [
            // A value-added service on the same day, and a date change: neither is the renewal.
            $this->walletLine('Wallet', $ends, 'VAS', ['subscription' => ['id' => '13525583', 'displayValue' => 'IPTV', 'type' => 'VAS', 'endsAt' => $ends]]),
            ['id' => 1, 'type' => 'CHANGE_DATE', 'paymentMode' => null, 'subscription' => ['id' => '13525583']],
            // Another subscription this system does not know, whose customer's ID happens to be our subscription's.
            [...$this->walletLine('Wallet', $ends), 'customer' => ['id' => '13525583'], 'subscription' => ['id' => '999', 'type' => 'BASE_PLAN', 'endsAt' => $ends], 'deviceUsername' => 'OTHER'],
            $this->walletLine('Card', $ends),
        ]];

        $response = $this->postJson('/sync/browser/run', [...$this->lists($ends), 'wallet' => $wallet])->assertOk();

        $this->assertStringContainsString('1 تجديد عُرف نوع دفعه (Card 1، Wallet 0)', $response->json('summary'));
        $renewal = AccountRenewal::sole();
        $this->assertSame(['card', 35000], [$renewal->payment_source, $renewal->company_amount]);
        // Only a label: no debt, no money.
        $this->assertSame(0, Debt::count());
        Livewire::test(RecentlyActivated::class)->assertSee('💳 Card');

        // Read again on the next sync: nothing changes, nothing doubles.
        $this->postJson('/sync/browser/run', [...$this->lists($ends), 'wallet' => $wallet])->assertOk();
        $this->assertSame('card', $renewal->fresh()->payment_source);
        $this->assertSame(1, AuditLog::where('action', 'renewal.payment_source')->count());
    }

    public function test_a_wallet_payment_seen_on_a_later_sync_labels_the_secondary_debt(): void
    {
        $this->postJson('/sync/browser/run', $this->lists('2026-09-01T10:00:00Z', 'Expired'))->assertOk();
        $ends = CarbonImmutable::now()->addDays(7)->addHour();
        // The renewal is seen first without the wallet log.
        $first = $this->postJson('/sync/browser/run', $this->lists($ends->toIso8601String()))->assertOk();
        $this->assertStringContainsString('لم تُقرأ حركات المحفظة', $first->json('summary'));
        $this->assertNull(AccountRenewal::sole()->payment_source);

        // The log shows the date as the page does (day/month/year).
        $line = $this->walletLine('Wallet', $ends->setTimezone(config('app.timezone'))->format('d/m/Y'));
        $this->postJson('/sync/browser/run', [...$this->lists($ends->toIso8601String()), 'wallet' => ['items' => [$line]]])->assertOk();

        $this->assertSame('wallet', AccountRenewal::sole()->payment_source);
        $this->assertSame(1, Debt::count());
        $this->get(DebtResource::getUrl('index', ['tab' => 'secondary']))->assertOk()->assertSee('👛 Wallet');
    }

    public function test_the_extension_reads_the_wallet_log_with_get_only(): void
    {
        $js = BrowserScripts::contentScript('https://subs.example', 'app');
        $this->assertStringContainsString("localStorage.setItem('subs_wallet_api'", $js);
        // The panel's own addresses (seen in its network log) come first.
        $this->assertStringContainsString("const SUBS_WALLET_LOG = '/api/transactions?sortCriteria.property=occuredAt&sortCriteria.direction=desc'", $js);
        $this->assertStringContainsString("const SUBS_ONT_PATH = '/api/subscriptions/device/ont'", $js);
        $this->assertStringContainsString('[base, SUBS_ONT_PATH,', $js);
        $this->assertStringContainsString('const paths = [SUBS_WALLET_LOG]', $js);
        // The subscriber pages' /api/transactions/payment-methods is not the log.
        $this->assertStringContainsString('const SUBS_WALLET_API = /\/api\/transactions(?:\?|$)/i', $js);
        $this->assertDoesNotMatchRegularExpression("/method:\\s*'(POST|PUT|PATCH|DELETE)'/i", $js);
    }

    public function test_the_received_power_of_each_running_device_is_read_and_coloured(): void
    {
        $ends = CarbonImmutable::now()->addDays(20)->toIso8601String();
        $this->postJson('/sync/browser/run', $this->lists($ends))->assertOk();

        // The activations sync no longer reads devices: that is its own run, so it never slows the sync.
        $this->assertArrayNotHasKey('ont', $this->postJson('/sync/browser/plan', $this->lists($ends))->assertOk()->json());
        // The readings run asks which devices to read (running ones, never read yet first).
        $this->postJson('/sync/browser/ont-plan')->assertOk()->assertJson(['ont' => ['FBG876F33P08']]);

        // What admin.ftth.iq/…/ont?username=… returned.
        $ont = ['FBG876F33P08' => ['model' => ['status' => ['id' => 0, 'entityType' => 'OntStatus', 'displayValue' => 'Up'], 'rxPower' => -29.4]]];
        $this->postJson('/sync/browser/ont', ['ont' => $ont])->assertOk()->assertJson(['summary' => 'قُرئت القدرة المستلمة لـ 1 جهاز']);

        $account = Account::sole();
        $this->assertSame(['-29.40', 'Up'], [(string) $account->rx_power, $account->ont_status]);
        $this->assertSame('bad', OpticalSignal::ofAccount($account));

        Livewire::test(ListSubscribers::class)
            ->assertSee('-29.40 · مشكلة بالكيبل')
            ->filterTable('signal', 'bad')->assertCanSeeTableRecords([Subscriber::sole()])
            ->filterTable('signal', 'good')->assertCountTableRecords(0);
        Livewire::test(ListSubscriberCards::class)->assertSee('مشكلة بالكيبل');
    }

    public function test_signal_levels(): void
    {
        $level = fn (?float $rx, ?string $status = 'Up') => OpticalSignal::level($rx, $status);
        $this->assertSame(['good', 'good', 'weak', 'bad', 'off', 'off', null], [
            $level(-17.0), $level(-25.0), $level(-27.5), $level(-29.0), $level(-40.0), $level(-20.0, 'Down'), $level(null, null),
        ]);
        $this->assertSame(['rx' => -23.47, 'status' => 'Up'], OntReadings::parse(['model' => ['status' => ['displayValue' => 'Up'], 'rxPower' => -23.47]]));
        $this->assertNull(OntReadings::parse(['model' => []]));
    }

    public function test_the_readings_run_is_for_users_allowed_to_sync_and_the_page_has_its_button(): void
    {
        $this->get(CompanySyncPage::getUrl())->assertOk()->assertSee('قراءة القدرة المستلمة')->assertSee('subs-ont-start', escape: false);
        $js = BrowserScripts::contentScript('https://subs.example', 'app');
        $this->assertStringContainsString("type: 'ont_plan'", $js);
        $this->assertStringContainsString("send({ type: 'run', customers, subscriptions, details, wallet })", $js, 'the activations sync sends no device readings');

        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        $this->actingAs($user);
        $this->postJson('/sync/browser/ont-plan')->assertForbidden();
        $this->postJson('/sync/browser/ont', ['ont' => []])->assertForbidden();
    }
}

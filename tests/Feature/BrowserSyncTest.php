<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Models\Subscriber;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
}

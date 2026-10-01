<?php

namespace Tests\Feature;

use App\Services\Settings;
use App\Sync\CompanySyncException;
use App\Sync\FtthApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Response shapes copied from the company panel's own requests (names shortened).
 */
class FtthApiClientTest extends TestCase
{
    private function configure(): void
    {
        $s = app(Settings::class);
        $s->set('sync.client_id', 'admin-portal');
        $s->set('sync.username', '07700000000');
        $s->set('sync.password', Crypt::encryptString('secret'));
    }

    private function fakeSite(): void
    {
        Http::fake([
            'sso.ftth.iq/*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
            'admin.ftth.iq/api/customers?*' => Http::response(['totalCount' => 1, 'items' => [
                ['id' => '2825350', 'displayValue' => 'أحمد كريم', 'primaryPhone' => '07701234567'],
            ]]),
            'admin.ftth.iq/api/subscriptions?*' => Http::response(['totalCount' => 1, 'items' => [[
                'username' => 'FBG876F33P08',
                'status' => ['id' => 1, 'displayValue' => 'Active'],
                'bundleId' => 'FTTH_BASIC',
                'services' => [['type' => ['displayValue' => 'Base'], 'value' => 'BASIC'], ['type' => 'Vas', 'value' => 'IPTV']],
                'zone' => ['id' => 9, 'displayValue' => 'FBG0876-2'],
                'expires' => '2026-10-15T10:00:00Z',
                'customer' => ['id' => '2825350', 'displayValue' => 'أحمد كريم'],
                'self' => ['id' => '13525583', 'displayValue' => 'FBG876F33P08'],
            ]]]),
            'admin.ftth.iq/api/customers/2825350' => Http::response(['model' => [
                'primaryContact' => ['mobile' => '07701234567'],
                'addresses' => [['gpsCoordinate' => ['latitude' => 33.31, 'longitude' => 44.36]]],
            ]]),
            'admin.ftth.iq/api/customers/subscriptions?*' => Http::response(['totalCount' => 1, 'items' => [[
                'self' => ['id' => '13525583'],
                'deviceDetails' => ['username' => 'FBG876F33P08', 'serial' => 'TDTC35980DF8', 'fdt' => ['displayValue' => 'FDT-1'], 'fat' => ['portNumber' => 3, 'displayValue' => 'FAT33']],
            ]]]),
        ]);
    }

    public function test_it_signs_in_and_reads_every_field_the_sync_needs(): void
    {
        $this->configure();
        $this->fakeSite();

        $records = iterator_to_array(app(FtthApiClient::class)->records(['needs_details' => fn () => true]));

        $this->assertSame([
            'customer_id' => '2825350', 'name' => 'أحمد كريم', 'phone' => '07701234567', 'plan' => 'BASIC', 'status' => 'Active',
            'ends_at' => '2026-10-15T10:00:00Z', 'zone' => 'FBG0876-2', 'gps' => '33.31,44.36', 'username' => 'FBG876F33P08',
            'serial' => 'TDTC35980DF8', 'fat' => 'FAT33', 'port' => '3', 'subscription_id' => '13525583',
        ], $records[0]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sso.ftth.iq') && $r['grant_type'] === 'password' && $r['client_id'] === 'admin-portal');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/subscriptions') && $r->hasHeader('Authorization', 'Bearer AT')
            && $r->hasHeader('X-Client-App', '53d57a7f-3f89-4e9d-873b-3d071bc6dd9f'));
        // Only reads: no POST to the panel.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'admin.ftth.iq') && $r->method() !== 'GET');
        // The refresh token is kept, encrypted, for the next run.
        $this->assertSame('RT', Crypt::decryptString(app(Settings::class)->get('sync.refresh_token')));
    }

    public function test_missing_credentials_give_a_clear_message(): void
    {
        Http::fake();
        app(Settings::class)->set('sync.client_id', 'admin-portal');

        $this->expectException(CompanySyncException::class);
        $this->expectExceptionMessage('يوزر وباسورد');

        iterator_to_array(app(FtthApiClient::class)->records());
    }

    public function test_a_refused_sign_in_explains_why(): void
    {
        $this->configure();
        Http::fake(['sso.ftth.iq/*' => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Client not allowed for direct access grants'], 400)]);

        try {
            iterator_to_array(app(FtthApiClient::class)->records());
            $this->fail('Sign-in should fail.');
        } catch (CompanySyncException $e) {
            $this->assertStringContainsString('مفتاح التجديد', $e->getMessage());
            $this->assertStringContainsString('unauthorized_client', $e->getMessage());
        }
    }

    public function test_a_pasted_refresh_token_signs_in_and_is_kept_alive(): void
    {
        $s = app(Settings::class);
        $s->set('sync.refresh_token', Crypt::encryptString('RT-from-browser'));
        Http::fake(['sso.ftth.iq/*' => Http::sequence()
            ->push(['access_token' => 'AT1', 'refresh_token' => 'RT2', 'expires_in' => 3600])
            ->push(['access_token' => 'AT2', 'refresh_token' => 'RT3', 'expires_in' => 3600])
            ->push(['access_token' => 'AT3', 'refresh_token' => 'RT4', 'expires_in' => 3600])]);

        $this->assertTrue(app(FtthApiClient::class)->keepAlive());
        $this->assertSame('RT2', Crypt::decryptString(app(Settings::class)->get('sync.refresh_token')));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'RT-from-browser' && $r['client_id'] === 'earthlink-portals');

        $this->assertTrue(app(FtthApiClient::class)->keepAlive());
        $this->assertSame('RT3', Crypt::decryptString(app(Settings::class)->get('sync.refresh_token')));
        $this->artisan('company:keep-session')->assertSuccessful();
    }

    public function test_a_refresh_token_is_renewed_at_the_realm_that_issued_it(): void
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['iss' => 'https://sso.ftth.iq/auth/realms/Partners', 'typ' => 'Refresh'])), '+/', '-_'), '=');
        app(Settings::class)->set('sync.token_url', 'https://wrong.example/token');
        app(Settings::class)->set('sync.refresh_token', Crypt::encryptString("eyJhbGciOiJIUzI1NiJ9.{$claims}.sig"));
        Http::fake(['sso.ftth.iq/*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT2', 'expires_in' => 3600])]);

        app(FtthApiClient::class)->keepAlive();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://sso.ftth.iq/auth/realms/Partners/protocol/openid-connect/token');
    }
}

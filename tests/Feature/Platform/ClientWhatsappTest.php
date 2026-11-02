<?php

namespace Tests\Feature\Platform;

use App\Filament\Pages\WhatsappSettings;
use App\Models\User;
use App\Platform\Tenant;
use App\Platform\TenantManager;
use App\WhatsApp\WahaGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/** Each client its own WhatsApp (QR) service; client 1's stays its own. */
class ClientWhatsappTest extends BaseTestCase
{
    use BuildsClientDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClients();
        Tenant::where('slug', 'wakeel2')->update(['status' => 'active', 'waha_url' => null, 'waha_api_key' => null, 'waha_webhook_secret' => null]);
        $this->withoutDefer();
    }

    private function second(): Tenant
    {
        return Tenant::where('slug', 'wakeel2')->sole();
    }

    public function test_the_service_keys_are_made_once_and_never_for_client_1(): void
    {
        $this->artisan('platform:whatsapp', ['slug' => 'main'])->assertFailed();
        $this->artisan('platform:whatsapp', ['slug' => 'wakeel2', '--url' => 'http://evil.example:3000'])->assertFailed();

        $this->artisan('platform:whatsapp', ['slug' => 'wakeel2', '--url' => 'http://waha-wakeel2:3000'])->assertSuccessful();
        $key = $this->second()->waha_api_key;
        $this->assertSame(64, strlen($key));
        $this->artisan('platform:whatsapp', ['slug' => 'wakeel2'])->expectsOutput("WAHA_API_KEY={$key}")->assertSuccessful();
        $this->assertSame($key, $this->second()->waha_api_key, 'made once');

        // Stored encrypted, never in clear.
        $raw = DB::connection('platform')->table('tenants')->where('slug', 'wakeel2')->value('waha_api_key');
        $this->assertNotSame($key, $raw);
    }

    public function test_a_client_uses_its_own_service_and_client_1_keeps_its_own(): void
    {
        $tenants = app(TenantManager::class);
        $tenants->activate($this->second());
        $this->assertFalse(WahaGateway::configured(), 'no WhatsApp until its service is set up');

        $this->artisan('platform:whatsapp', ['slug' => 'wakeel2']);
        $tenants->activate(null);
        $tenants->activate($this->second());
        $this->assertSame(['http://waha-wakeel2:3000', 'http://app:8080/whatsapp/qr-webhook/wakeel2'], [config('whatsapp.waha.url'), config('whatsapp.waha.webhook_url')]);
        $this->assertSame($this->second()->waha_api_key, config('whatsapp.waha.api_key'));
        $this->assertTrue(WahaGateway::configured());
        $this->assertNull(config('whatsapp.waha_notify.api_key'));

        $tenants->activate(null);
        $this->assertSame('home-waha-key', config('whatsapp.waha.api_key'));
        $this->assertNotSame('http://waha-wakeel2:3000', config('whatsapp.waha.url'));
    }

    public function test_its_whatsapp_settings_speak_to_the_client_not_the_server(): void
    {
        app(TenantManager::class)->activate($this->second());
        $this->actingAs(User::where('username', 'admin')->sole());
        $this->get('http://wakeel2.test'.parse_url(WhatsappSettings::getUrl(), PHP_URL_PATH))
            ->assertOk()->assertSee('تواصل مع إدارة المنصة لتجهيزها')->assertDontSee('WAHA_API_KEY');
    }

    public function test_its_messages_come_back_to_it_signed_with_its_own_secret(): void
    {
        $this->artisan('platform:whatsapp', ['slug' => 'wakeel2']);
        $secret = $this->second()->waha_webhook_secret;
        $body = json_encode(['event' => 'session.status', 'payload' => ['status' => 'WORKING']]);
        $sign = fn (string $key) => ['X-Webhook-Hmac' => hash_hmac('sha512', $body, $key), 'Content-Type' => 'application/json'];

        $this->call('POST', 'http://app/whatsapp/qr-webhook/wakeel2', [], [], [], $this->transformHeadersToServerVars($sign($secret)), $body)->assertOk();
        $this->assertSame(self::SECOND, DB::connection()->getDatabaseName());

        // Signed with another key (client 1's or anyone's): refused.
        $this->call('POST', 'http://app/whatsapp/qr-webhook/wakeel2', [], [], [], $this->transformHeadersToServerVars($sign('home-secret')), $body)->assertStatus(401);
        // A client without its own service, or an unknown one: no such address.
        $this->call('POST', 'http://app/whatsapp/qr-webhook/nobody', [], [], [], $this->transformHeadersToServerVars($sign($secret)), $body)->assertNotFound();
        // Only from inside the server: on a public address the path does not pick the client.
        $this->assertTrue(app(TenantManager::class)->identify('localhost', 'whatsapp/qr-webhook/wakeel2'));
        $this->assertSame(self::HOME, DB::connection()->getDatabaseName());
    }
}

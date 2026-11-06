<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Sync\BrowserScripts;
use Tests\TestCase;

/** An account kept on a separate machine (an RDP) just for the sync: it syncs and does nothing else. */
class SyncOnlyUserTest extends TestCase
{
    public function test_a_user_with_only_the_sync_permission_can_sync_and_nothing_else(): void
    {
        $closed = ['/payments', '/expenses', '/subscribers', '/money-accounts', '/users', '/audit-logs'];
        foreach ($closed as $url) {
            $this->assertNotSame(404, $this->get($url)->status(), $url); // they exist
        }
        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $user->givePermissionTo('sync.run');
        $this->flushSession(); // a new sign-in, not the admin's session
        $this->actingAs($user);

        $this->get('/company-sync')->assertOk()->assertSee('id="subs-auto-start"', false)->assertSee('المزامنة التلقائية');
        $this->get('/sync/browser')->assertOk();
        $this->get('/sync/extension.zip')->assertOk();
        $this->postJson('/sync/browser/ont-plan')->assertOk();

        // Money, subscribers, settings and the log stay closed.
        foreach ($closed as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertFalse($user->can('payments.create'));
        $this->assertFalse($user->can('subscribers.view'));
    }

    public function test_the_extension_can_keep_syncing_on_its_own(): void
    {
        $script = BrowserScripts::contentScript('https://subs.example', 'admin');

        $this->assertStringContainsString('subs-auto-', $script);
        $this->assertStringContainsString('const subsAuto', $script);
        // It never signs in: without the user's own panel session it waits.
        $this->assertStringContainsString('بانتظار تسجيل الدخول', $script);
        $this->assertStringNotContainsString('password', strtolower($script));
        $this->assertStringContainsString('"version": "1.4.1"', BrowserScripts::manifest('https://subs.example'));
    }

    public function test_a_page_left_open_gets_a_fresh_token(): void
    {
        $this->getJson('/sync/browser/token')->assertOk()->assertJsonStructure(['token']);
        auth()->logout();
        $this->getJson('/sync/browser/token')->assertUnauthorized();
    }

    public function test_a_lost_company_session_alerts_the_staff_once_in_three_hours(): void
    {
        WhatsappNumber::create(['phone' => '9647701111111', 'label' => 'علي', 'user_id' => $this->admin->id, 'is_active' => true, 'receives_alerts' => true]);

        $this->postJson('/sync/browser/session-lost')->assertOk()->assertJson(['queued' => 1]);
        $this->postJson('/sync/browser/session-lost')->assertOk()->assertJson(['queued' => 0]);

        $sent = WhatsappOutbox::sole();
        $this->assertSame('alert_sync', $sent->kind);
        $this->assertStringContainsString('سجّل الدخول لموقع الشركة', $sent->body);
    }
}

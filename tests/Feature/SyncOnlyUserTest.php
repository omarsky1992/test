<?php

namespace Tests\Feature;

use App\Models\User;
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
        $this->assertStringContainsString('"version": "1.4.0"', BrowserScripts::manifest('https://subs.example'));
    }
}

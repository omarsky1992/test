<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriberService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

/** Revealing a stored subscriber password is re-checked in the service, not only on the button. */
class RevealSecretTest extends TestCase
{
    public function test_the_admin_sees_the_secret(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame('pass123', app(SubscriberService::class)->revealSecret($this->account()));
    }

    public function test_an_employee_without_the_permission_is_refused_in_the_service(): void
    {
        $account = $this->account();
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $this->actingAs($employee);
        $this->assertFalse($employee->can('accounts.view_secret'));

        $this->expectException(AuthorizationException::class);
        app(SubscriberService::class)->revealSecret($account);
    }
}

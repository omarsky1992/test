<?php

namespace Tests;

use App\Enums\MoneyAccountKind;
use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Ledger;
use App\Services\SubscriberService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 11:00:00'));
        $this->admin = User::first();
        $this->actingAs($this->admin);
    }

    protected function travelToTime(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
    }

    protected function account(string $name = 'علي حسين', ?string $username = null, string $phone = '07902223141'): Account
    {
        $subscriber = app(SubscriberService::class)->create(
            ['full_name' => $name, 'phone' => $phone],
            ['username' => $username ?? 'user'.random_int(1000, 99999), 'secret' => 'pass123'],
        );

        return $subscriber->accounts()->first();
    }

    protected function plan(string $code = 'basic'): ServicePlan
    {
        return ServicePlan::where('code', $code)->firstOrFail();
    }

    protected function cash(): MoneyAccount
    {
        return MoneyAccount::where('kind', MoneyAccountKind::Cash)->firstOrFail();
    }

    protected function companyBox(): MoneyAccount
    {
        return MoneyAccount::where('kind', MoneyAccountKind::Company)->firstOrFail();
    }

    protected function balanceOf(string|int $ledgerAccount, ?int $accountId = null): int
    {
        return app(Ledger::class)->balance($ledgerAccount, $accountId);
    }
}

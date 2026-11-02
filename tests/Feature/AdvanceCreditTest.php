<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Debt;
use App\Services\DebtService;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\RenewalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An account never shows advance credit beside an unpaid debt: the credit always pays it. */
class AdvanceCreditTest extends TestCase
{
    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    public function test_an_advance_pays_the_open_debts_first_and_only_the_rest_is_credit(): void
    {
        $account = $this->account();
        $primary = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual, debtDate: CarbonImmutable::now()->subDays(3));
        $secondary = app(DebtService::class)->create($account, 35000, DebtBucket::Secondary, DebtSource::Manual);

        $payment = $this->payments()->record($account, 70000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);

        $this->assertSame([DebtStatus::Paid, DebtStatus::Paid], [$primary->fresh()->status, $secondary->fresh()->status]);
        $this->assertSame(15000, $this->payments()->creditBalance($account));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_PRIMARY, $account->id) + $this->balanceOf(Ledger::AR_SECONDARY, $account->id));

        // Voiding the receipt puts everything back as it was.
        $this->payments()->void($payment, 'خطأ');
        $this->assertSame([20000, 35000, 0], [$primary->fresh()->balance, $secondary->fresh()->balance, $this->payments()->creditBalance($account)]);
    }

    public function test_a_smaller_advance_pays_the_oldest_debt_and_leaves_no_credit(): void
    {
        $account = $this->account();
        $old = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual, debtDate: CarbonImmutable::now()->subDays(3));
        $new = app(DebtService::class)->create($account, 35000, DebtBucket::Secondary, DebtSource::Manual);

        $this->payments()->record($account, 30000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);

        $this->assertSame([0, 25000], [$old->fresh()->balance, $new->fresh()->balance]);
        $this->assertSame(0, $this->payments()->creditBalance($account));
    }

    public function test_a_new_debt_is_paid_from_the_credit_already_there(): void
    {
        $account = $this->account();
        $this->payments()->record($account, 50000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);
        $this->assertSame(50000, $this->payments()->creditBalance($account));

        // A manual primary debt…
        $debt = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        $this->assertSame([DebtStatus::Paid, 30000], [$debt->status, $this->payments()->creditBalance($account)]);

        // …and a secondary debt found by the company sync (bigger than what is left).
        $account->update(['current_plan_id' => $this->plan()->id, 'external_ends_at' => now()->subDay(), 'company_days_left' => 0]);
        $renewal = app(RenewalService::class)->record($account->fresh(), 0, 7, now()->subDay()->toImmutable(), now()->addDays(7)->toImmutable());
        $renewalDebt = Debt::find($renewal->debt_id);
        $this->assertSame(0, $this->payments()->creditBalance($account));
        $this->assertSame($renewalDebt->original_amount - 30000, $renewalDebt->balance);
    }

    public function test_existing_accounts_with_both_are_listed_then_settled_on_request(): void
    {
        $account = $this->account();
        // As before this change: credit and a debt side by side (posted directly, the old way).
        $this->payments()->record($account, 40000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);
        $debt = Debt::create([
            'number' => 'D-OLD-1', 'account_id' => $account->id, 'subscriber_id' => $account->subscriber_id, 'branch_id' => $account->branch_id,
            'source' => DebtSource::Manual, 'bucket' => DebtBucket::Primary, 'original_amount' => 25000, 'paid_amount' => 0, 'balance' => 25000,
            'debt_date' => now(), 'status' => DebtStatus::Open,
        ]);
        app(Ledger::class)->post('manual_debt', CarbonImmutable::now(), [
            ['account' => Ledger::AR_PRIMARY, 'debit' => 25000, 'subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id],
            ['account' => Ledger::REV_OTHER, 'credit' => 25000],
        ], $debt, 'old debt', $account->branch_id);

        $this->artisan('credits:settle')->expectsOutputToContain('would pay 25,000')->assertSuccessful();
        $this->assertSame(DebtStatus::Open, $debt->fresh()->status, 'a dry run changes nothing');

        $this->artisan('credits:settle', ['--apply' => true])->expectsOutputToContain('paid 25,000')->assertSuccessful();
        $this->assertSame([DebtStatus::Paid, 15000], [$debt->fresh()->status, $this->payments()->creditBalance($account)]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'credit.applied')->where('source', 'system')->exists());
        $this->artisan('credits:settle')->expectsOutput('No account has credit beside a debt.');
    }
}

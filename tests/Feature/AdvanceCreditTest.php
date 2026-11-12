<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Debt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\DebtService;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\RenewalService;
use App\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Old advance credit: an explicit receipt pays open debts first, but credit never pays a later debt on its own. */
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

    public function test_old_credit_never_pays_a_new_debt(): void
    {
        $account = $this->account();
        $this->payments()->record($account, 50000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);

        // A manual primary debt…
        $debt = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        $this->assertSame([DebtStatus::Open, 50000], [$debt->fresh()->status, $this->payments()->creditBalance($account)]);

        // …and a secondary debt found by the company sync stay owed.
        $account->update(['current_plan_id' => $this->plan()->id, 'external_ends_at' => now()->subDay(), 'company_days_left' => 0]);
        $renewal = app(RenewalService::class)->record($account->fresh(), 0, 7, now()->subDay()->toImmutable(), now()->addDays(7)->toImmutable());
        $renewalDebt = Debt::find($renewal->debt_id);
        $this->assertSame($renewalDebt->original_amount, $renewalDebt->balance);
        $this->assertSame(50000, $this->payments()->creditBalance($account));
        $this->assertFalse(DB::table('financial_transactions')->where('txn_type', 'credit_application')->exists());
    }

    /** As the old code did: credit already on the account paid a new debt on its own. */
    private function oldCreditApplication(Payment $payment, Debt $debt, int $amount): void
    {
        $tag = ['subscriber_id' => $debt->subscriber_id, 'account_id' => $debt->account_id];
        $txn = app(Ledger::class)->post('credit_application', CarbonImmutable::now(), [
            ['account' => Ledger::CUSTOMER_CREDIT, 'debit' => $amount, ...$tag],
            ['account' => Ledger::AR_PRIMARY, 'credit' => $amount, ...$tag, 'debt_id' => $debt->id],
        ], $debt, 'old credit application', $debt->branch_id);
        PaymentAllocation::create(['payment_id' => $payment->id, 'debt_id' => $debt->id, 'amount' => $amount, 'txn_id' => $txn->id]);
        $debt->update(['paid_amount' => $amount, 'balance' => $debt->original_amount - $amount, 'status' => $amount >= $debt->original_amount ? DebtStatus::Paid : DebtStatus::Partial]);
    }

    public function test_a_debt_paid_from_old_credit_is_owed_again_from_the_debts_table(): void
    {
        $treasury = app(TreasuryService::class);
        $account = $this->account('صادق');
        $payment = $this->payments()->record($account, 40000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);
        $debt = app(DebtService::class)->create($account, 35000, DebtBucket::Primary, DebtSource::Manual);
        $this->oldCreditApplication($payment, $debt, 35000);
        $box = $treasury->balance($this->cash());
        $this->assertSame([DebtStatus::Paid, 5000], [$debt->fresh()->status, $this->payments()->creditBalance($account)]);

        Livewire::test(ListDebts::class)->set('activeTab', 'all')
            ->assertTableActionVisible('undoCredit', $debt)
            ->callTableAction('undoCredit', $debt, data: ['reason' => 'تجربة'])->assertHasNoTableActionErrors()->assertNotified();

        $debt->refresh();
        $this->assertSame([DebtStatus::Open, 35000], [$debt->status, $debt->balance]);
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_PRIMARY, $account->id));
        // All the credit (the 35,000 returned and the 5,000 left) is now a cash activation; no box moved.
        $this->assertSame(0, $this->payments()->creditBalance($account));
        $this->assertSame(40000, (int) $payment->fresh()->activation_amount);
        $this->assertSame($box, $treasury->balance($this->cash()));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'credit.unapplied')->exists());

        // Done once: the button goes away, and voiding the old receipt still balances.
        Livewire::test(ListDebts::class)->set('activeTab', 'all')->assertTableActionHidden('undoCredit', $debt);
        $this->payments()->void($payment->fresh(), 'تجربة');
        $this->assertSame([0, 0], [$this->balanceOf(Ledger::CUSTOMER_CREDIT), $this->balanceOf(Ledger::RENEWALS_CLEARING)]);
        $this->assertSame(35000, $debt->fresh()->balance);
    }

    public function test_the_undo_command_lists_then_owes_the_debts_again(): void
    {
        $account = $this->account();
        $payment = $this->payments()->record($account, 30000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);
        $debt = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        $this->oldCreditApplication($payment, $debt, 20000);

        $this->artisan('credits:undo', ['--since' => now()->format('Y-m-d')])->expectsOutputToContain('paid from credit 20,000')->assertSuccessful();
        $this->assertSame(DebtStatus::Paid, $debt->fresh()->status, 'a dry run changes nothing');
        $this->artisan('credits:undo', ['--since' => now()->addDay()->format('Y-m-d')])->expectsOutput('No debt was paid from advance credit.');

        $this->artisan('credits:undo', ['--apply' => true])->expectsOutputToContain('owed again 20,000')->assertSuccessful();
        $this->assertSame([DebtStatus::Open, 0], [$debt->fresh()->status, $this->payments()->creditBalance($account)]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'credit.unapplied')->where('source', 'system')->exists());
        $this->artisan('credits:undo')->expectsOutput('No debt was paid from advance credit.');
    }

    public function test_undoing_is_for_the_admin_only(): void
    {
        $account = $this->account();
        $payment = $this->payments()->record($account, 30000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);
        $debt = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        $this->oldCreditApplication($payment, $debt, 20000);

        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        $this->expectException(AuthorizationException::class);
        $this->payments()->undoCreditApplications($debt);
    }

    public function test_the_accounts_holding_advance_credit_are_counted(): void
    {
        $this->artisan('credits:list')->expectsOutput('No account has advance credit.')->assertSuccessful();

        app(PaymentService::class)->record($this->account('علي'), 30000, moneyAccount: $this->cash(), type: PaymentType::Advance);
        app(PaymentService::class)->record($this->account('حسن'), 5000, moneyAccount: $this->cash(), type: PaymentType::Advance);

        $this->artisan('credits:list')
            ->expectsOutputToContain('credit 30,000')
            ->expectsOutputToContain('Accounts with advance credit: 2 · total 35,000 IQD.')
            ->assertSuccessful();
    }

    public function test_old_advance_credit_is_cleared_without_touching_boxes_or_debts(): void
    {
        $payments = app(PaymentService::class);
        $treasury = app(TreasuryService::class);
        $ali = $this->account('علي');
        $hasan = $this->account('حسن');
        $old = $payments->record($ali, 30000, moneyAccount: $this->cash(), type: PaymentType::Advance);
        $payments->record($hasan, 5000, moneyAccount: $this->cash(), type: PaymentType::Advance);
        $debt = app(DebtService::class)->create($this->account('كرار'), 20000, DebtBucket::Primary);
        $hasanDebt = app(DebtService::class)->create($hasan, 3000, DebtBucket::Primary); // beside his credit: stays owed
        $box = $treasury->balance($this->cash());

        $this->artisan('credits:clear')->expectsOutputToContain('Would clear 35,000 IQD of advance credit on 2 accounts')->assertSuccessful();
        $this->assertSame(30000, $payments->creditBalance($ali));

        Livewire::test(ListPayments::class)
            ->assertActionVisible('clearCredits')
            ->callAction('clearCredits', data: ['confirm' => 'غلط'])->assertHasActionErrors(['confirm']);
        $this->assertSame(30000, $payments->creditBalance($ali));
        Livewire::test(ListPayments::class)
            ->callAction('clearCredits', data: ['confirm' => 'حذف'])->assertHasNoActionErrors()->assertNotified();

        $this->assertSame([0, 0], [$payments->creditBalance($ali), $payments->creditBalance($hasan)]);
        $this->assertSame(0, $this->balanceOf(Ledger::CUSTOMER_CREDIT));
        $this->assertSame(-35000, $this->balanceOf(Ledger::RENEWALS_CLEARING));
        // Boxes and other subscribers' debts are exactly as they were.
        $this->assertSame($box, $treasury->balance($this->cash()));
        $this->assertSame([20000, 3000], [$debt->fresh()->balance, $hasanDebt->fresh()->balance]);
        $this->assertSame(30000, (int) $old->fresh()->activation_amount);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'credit.cleared')->count() === 2);

        // A debt recorded afterwards is not paid from the cleared money, and the old receipt can still be voided.
        $later = app(DebtService::class)->create($ali, 10000, DebtBucket::Primary);
        $this->assertSame(10000, $later->fresh()->balance);
        $payments->void($old->fresh(), 'تجربة');
        $this->assertSame($box - 30000, $treasury->balance($this->cash()));
        $this->assertSame(0, $this->balanceOf(Ledger::CUSTOMER_CREDIT));

        // Nothing left: the button goes away and the command says so.
        Livewire::test(ListPayments::class)->assertActionHidden('clearCredits');
        $this->artisan('credits:clear', ['--apply' => true])->expectsOutput('No account has advance credit.');
    }

    public function test_clearing_the_credit_is_for_the_admin_only(): void
    {
        $payments = app(PaymentService::class);
        $account = $this->account('علي');
        $payments->record($account, 30000, moneyAccount: $this->cash(), type: PaymentType::Advance);

        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        try {
            $payments->clearCredit($account);
            $this->fail('An employee cleared advance credit.');
        } catch (AuthorizationException) {
        }
        $this->actingAs($this->admin);
        $this->assertSame(30000, $payments->creditBalance($account));

        $this->artisan('credits:clear', ['--apply' => true])->expectsOutputToContain('Cleared 30,000 IQD')->assertSuccessful();
        $this->assertSame(0, $payments->creditBalance($account));
    }
}

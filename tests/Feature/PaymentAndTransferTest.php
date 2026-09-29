<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\Settlement;
use App\Exceptions\BusinessRuleException;
use App\Models\Debt;
use App\Services\ActivationService;
use App\Services\DebtService;
use App\Services\Ledger;
use App\Services\PaymentService;
use Tests\TestCase;

class PaymentAndTransferTest extends TestCase
{
    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    public function test_transfer_moves_an_unpaid_seven_day_debt_to_primary_and_adds_23_days(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->travelToTime('2026-09-28 09:00');
        $transfer = app(DebtService::class)->transfer($activation->debt);

        $this->assertSame(23, $transfer->days_added);
        $this->assertSame(35000, $transfer->amount);
        $this->assertSame(DebtBucket::Primary, $activation->debt->fresh()->bucket);
        $this->assertSame(CompletedVia::Transfer, $activation->fresh()->completed_via);
        $this->assertSame('2026-10-21 11:00:00', $activation->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_PRIMARY));
    }

    public function test_partial_payment_alone_adds_no_days(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->payments()->record($account, 20000, PaymentMethod::Electronic, $this->cash(), receiverName: 'حسن');

        $this->assertSame(CompletionStatus::Pending, $activation->fresh()->completion_status);
        $this->assertSame(DebtStatus::Partial, $activation->debt->fresh()->status);
        $this->assertSame(15000, $this->balanceOf(Ledger::AR_SECONDARY));
    }

    public function test_partial_payment_with_transfer_of_the_rest_adds_23_days(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->travelToTime('2026-09-29 14:40');
        $payment = $this->payments()->record($account, 20000, PaymentMethod::Electronic, $this->cash(), receiverName: 'حسن', transferRemainder: true);

        $debt = $activation->debt->fresh();
        $this->assertSame(DebtBucket::Primary, $debt->bucket);
        $this->assertSame(15000, $debt->balance);
        $this->assertSame(15000, $debt->transfers()->sole()->amount);
        $this->assertSame('2026-10-22 14:40:00', $activation->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(15000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame('R-2026-000001', $payment->receipt_number);
    }

    public function test_payment_goes_to_the_oldest_debt_first_and_the_rest_becomes_credit(): void
    {
        $account = $this->account();
        $debts = app(DebtService::class);
        $old = $debts->create($account, 10000, debtDate: now()->subDays(10)->toImmutable());
        $new = $debts->create($account, 20000);

        $this->payments()->record($account, 40000, PaymentMethod::Cash, $this->cash());

        $this->assertSame(DebtStatus::Paid, $old->fresh()->status);
        $this->assertSame(DebtStatus::Paid, $new->fresh()->status);
        $this->assertSame(10000, $this->payments()->creditBalance($account));
    }

    public function test_advance_credit_pays_a_later_activation(): void
    {
        $account = $this->account();
        $this->payments()->record($account, 50000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance);

        $activation = app(ActivationService::class)->activate($account, $this->plan('plus'), ActivationKind::Full30, settlement: Settlement::Credit);

        $this->assertSame(DebtStatus::Paid, $activation->debt->status);
        $this->assertSame(5000, $this->payments()->creditBalance($account));
    }

    public function test_repeated_submission_with_the_same_key_creates_one_receipt(): void
    {
        $account = $this->account();
        $key = '5f1c7a1e-2f2d-4f1a-9a7e-3b2e1f0a9c11';

        $this->payments()->record($account, 5000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance, idempotencyKey: $key);
        $this->payments()->record($account, 5000, PaymentMethod::Cash, $this->cash(), PaymentType::Advance, idempotencyKey: $key);

        $this->assertSame(1, $account->payments()->count());
    }

    public function test_electronic_payment_requires_the_receiver_name(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->payments()->record($this->account(), 5000, PaymentMethod::Electronic, $this->cash());
    }

    public function test_voiding_a_receipt_reopens_the_debt_in_its_current_bucket(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);
        $payment = $this->payments()->record($account, 20000, PaymentMethod::Cash, $this->cash(), transferRemainder: true);

        $this->payments()->void($payment, 'مبلغ مكرر');

        $debt = $activation->debt->fresh();
        $this->assertSame(DocumentStatus::Voided, $payment->fresh()->status);
        $this->assertSame(DebtStatus::Open, $debt->status);
        $this->assertSame(35000, $debt->balance);
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(0, $this->balanceOf($this->cash()->ledger_account_id));
    }

    public function test_a_debt_with_payments_cannot_be_deleted(): void
    {
        $account = $this->account();
        $debt = app(DebtService::class)->create($account, 10000);
        $this->payments()->record($account, 4000, PaymentMethod::Cash, $this->cash());

        $this->expectException(BusinessRuleException::class);
        app(DebtService::class)->void($debt, 'خطأ');
    }

    public function test_deleting_a_manual_debt_reverses_it(): void
    {
        $account = $this->account();
        $debt = app(DebtService::class)->create($account, 10000, DebtBucket::Secondary, DebtSource::Opening);

        app(DebtService::class)->void($debt, 'دين مكرر');

        $this->assertSame(DebtStatus::Voided, $debt->fresh()->status);
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(0, $this->balanceOf(Ledger::OPENING_EQUITY));
    }

    public function test_ledger_debt_buckets_always_match_the_debts_table(): void
    {
        $service = app(ActivationService::class);
        foreach (range(1, 5) as $i) {
            $account = $this->account("مشترك {$i}");
            $activation = $service->activate($account, $this->plan(), ActivationKind::Partial7);
            match ($i % 3) {
                0 => $this->payments()->record($account, 35000, PaymentMethod::Cash, $this->cash()),
                1 => app(DebtService::class)->transfer($activation->debt),
                2 => $this->payments()->record($account, 10000, PaymentMethod::Cash, $this->cash()),
            };
        }

        $this->assertSame((int) Debt::where('bucket', 'secondary')->where('status', '<>', 'voided')->sum('balance'), $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame((int) Debt::where('bucket', 'primary')->where('status', '<>', 'voided')->sum('balance'), $this->balanceOf(Ledger::AR_PRIMARY));
    }
}

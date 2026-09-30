<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\PaymentMethodType;
use App\Services\ActivationService;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    private function method(string $code): PaymentMethodType
    {
        return PaymentMethodType::where('code', $code)->firstOrFail();
    }

    public function test_one_receipt_can_be_paid_with_cash_and_a_wallet(): void
    {
        $wallet = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'زين كاش – حسن', 'حسن');
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        $payment = app(PaymentService::class)->record($account, lines: [
            ['method' => $this->method('cash'), 'amount' => 20000],
            ['method' => $this->method('zain_cash'), 'money_account' => $wallet, 'amount' => 15000, 'receiver' => 'حسن', 'reference' => 'ZC-1'],
        ]);

        $this->assertSame(35000, $payment->amount);
        $this->assertSame(PaymentMethod::Mixed, $payment->method);
        $this->assertCount(2, $payment->lines);
        $this->assertSame(DebtStatus::Paid, $activation->debt->fresh()->status);
        $this->assertSame(20000, $this->balanceOf($this->cash()->ledger_account_id));
        $this->assertSame(15000, $this->balanceOf($wallet->ledger_account_id));

        app(PaymentService::class)->void($payment, 'اختبار');

        $this->assertSame(DocumentStatus::Voided, $payment->fresh()->status);
        $this->assertSame(0, $this->balanceOf($this->cash()->ledger_account_id));
        $this->assertSame(0, $this->balanceOf($wallet->ledger_account_id));
    }

    public function test_a_method_that_needs_a_reference_refuses_a_line_without_one(): void
    {
        $bank = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'حساب الرافدين');

        $this->expectException(BusinessRuleException::class);
        app(PaymentService::class)->record($this->account(), lines: [
            ['method' => $this->method('bank_transfer'), 'money_account' => $bank, 'amount' => 10000],
        ]);
    }

    public function test_a_new_method_added_by_the_admin_works_without_code_changes(): void
    {
        $box = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'فاست باي');
        $method = PaymentMethodType::create(['code' => 'fastpay', 'name_ar' => 'فاست باي', 'category' => 'electronic', 'money_account_id' => $box->id]);

        $payment = app(PaymentService::class)->record($this->account(), type: \App\Enums\PaymentType::Advance, lines: [
            ['method' => $method->id, 'amount' => 5000],
        ]);

        $this->assertSame($box->id, $payment->lines->sole()->money_account_id);
        $this->assertSame(5000, $this->balanceOf($box->ledger_account_id));
    }

    public function test_the_total_must_match_the_lines(): void
    {
        $this->expectException(BusinessRuleException::class);
        app(PaymentService::class)->record($this->account(), amount: 30000, lines: [
            ['method' => $this->method('cash'), 'amount' => 20000],
        ]);
    }
}

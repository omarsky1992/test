<?php

namespace Tests\Feature;

use App\Enums\MoneyAccountKind;
use App\Models\PaymentMethodType;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use Tests\TestCase;

class ReceiptMethodsTest extends TestCase
{
    public function test_every_payment_method_name_is_printed_on_the_receipt(): void
    {
        $wallet = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'محفظة المدير', 'المدير');
        $account = $this->account();

        $payment = app(PaymentService::class)->record($account, lines: [
            ['method' => PaymentMethodType::where('code', 'cash')->first(), 'money_account' => $this->cash(), 'amount' => 10000],
            ['method' => PaymentMethodType::where('code', 'zain_cash')->first(), 'money_account' => $wallet, 'amount' => 15000, 'receiver' => 'المدير'],
            ['method' => PaymentMethodType::where('code', 'super_key')->first(), 'money_account' => $wallet, 'amount' => 5000, 'reference' => 'SK-7781'],
        ], type: \App\Enums\PaymentType::Advance);

        $this->get(route('receipts.show', $payment))->assertOk()
            ->assertSee('نقدي')->assertSee('زين كاش')->assertSee('سوبر كي')->assertSee('SK-7781');
    }

    public function test_a_payment_method_added_later_appears_without_code_changes(): void
    {
        $wallet = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'محفظة', 'المدير');
        $method = PaymentMethodType::create(['code' => 'fastpay', 'name_ar' => 'فاست باي', 'category' => 'electronic', 'money_account_id' => $wallet->id, 'is_active' => true]);

        $payment = app(PaymentService::class)->record($this->account(), lines: [['method' => $method, 'amount' => 20000]], type: \App\Enums\PaymentType::Advance);

        $this->get(route('receipts.show', $payment))->assertOk()->assertSee('فاست باي');
    }
}

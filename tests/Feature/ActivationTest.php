<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\PeriodType;
use App\Enums\PromotionAudience;
use App\Enums\PromotionFunding;
use App\Enums\Settlement;
use App\Exceptions\BusinessRuleException;
use App\Models\Promotion;
use App\Services\ActivationService;
use App\Services\Ledger;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    private function activations(): ActivationService
    {
        return app(ActivationService::class);
    }

    public function test_seven_day_activation_creates_a_secondary_debt_and_charges_the_company_balance(): void
    {
        $account = $this->account();

        $activation = $this->activations()->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->assertSame('2026-09-21 11:00:00', $activation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 11:00:00', $activation->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(CompletionStatus::Pending, $activation->completion_status);
        $this->assertSame(DebtBucket::Secondary, $activation->debt->bucket);
        $this->assertSame(35000, $activation->debt->balance);
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(-35000, $this->balanceOf($this->companyBox()->ledger_account_id));
        $this->assertSame('2026-09-28 11:00:00', $account->fresh()->service_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_secondary_debts_total_adds_up_across_subscribers(): void
    {
        $this->activations()->activate($this->account('أحمد'), $this->plan(), ActivationKind::Partial7);
        $this->activations()->activate($this->account('علي'), $this->plan(), ActivationKind::Partial7);

        $this->assertSame(70000, $this->balanceOf(Ledger::AR_SECONDARY));
    }

    public function test_thirty_days_is_exactly_thirty_times_twenty_four_hours(): void
    {
        $activation = $this->activations()->activate(
            $this->account(), $this->plan('plus'), ActivationKind::Full30, CarbonImmutable::parse('2026-09-29 14:35'),
        );

        $this->assertSame('2026-10-29 14:35:00', $activation->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(30 * 86400, $activation->ends_at->getTimestamp() - $activation->starts_at->getTimestamp());
        $this->assertSame(DebtBucket::Primary, $activation->debt->bucket);
        $this->assertSame(CompletionStatus::NotRequired, $activation->completion_status);
    }

    public function test_renewal_is_queued_after_the_running_subscription(): void
    {
        $account = $this->account();
        $first = $this->activations()->activate($account, $this->plan(), ActivationKind::Full30);

        $this->travelToTime('2026-10-01 09:00');
        $second = $this->activations()->activate($account, $this->plan(), ActivationKind::Full30);

        $this->assertTrue($second->starts_at->equalTo($first->ends_at));
        $this->assertSame('2026-11-20 11:00:00', $second->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_full_payment_inside_seven_days_completes_to_exactly_thirty_days(): void
    {
        $account = $this->account();
        $activation = $this->activations()->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->travelToTime('2026-09-25 14:00');
        app(PaymentService::class)->record($account, 35000, PaymentMethod::Cash, $this->cash());

        $activation->refresh();
        $this->assertSame(CompletionStatus::Completed, $activation->completion_status);
        $this->assertSame(CompletedVia::Payment, $activation->completed_via);
        $this->assertSame('2026-10-21 11:00:00', $activation->ends_at->format('Y-m-d H:i:s'));
        $extension = $activation->periods()->where('period_type', PeriodType::Extension23)->sole();
        $this->assertSame('2026-09-28 11:00:00', $extension->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(35000, $this->balanceOf($this->cash()->ledger_account_id));
    }

    public function test_completion_after_the_seven_days_starts_the_23_days_at_that_moment(): void
    {
        $account = $this->account();
        $activation = $this->activations()->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->travelToTime('2026-09-29 14:40');
        app(PaymentService::class)->record($account, 35000, PaymentMethod::Cash, $this->cash());

        $this->assertSame('2026-10-22 14:40:00', $activation->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_completing_before_a_queued_renewal_moves_the_renewal_back(): void
    {
        $account = $this->account();
        $partial = $this->activations()->activate($account, $this->plan(), ActivationKind::Partial7);
        $renewal = $this->activations()->activate($account, $this->plan(), ActivationKind::Full30);
        $this->assertTrue($renewal->starts_at->equalTo($partial->ends_at));

        $this->travelToTime('2026-09-24 10:00');
        app(PaymentService::class)->record($account, 35000, PaymentMethod::Cash, $this->cash(), debtIds: [$partial->debt->id]);

        $this->assertSame('2026-10-21 11:00:00', $partial->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 11:00:00', $renewal->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 11:00:00', $account->fresh()->service_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_employee_can_move_the_start_and_the_end_follows(): void
    {
        $activation = $this->activations()->activate($this->account(), $this->plan(), ActivationKind::Partial7);

        $this->activations()->editStart($activation, CarbonImmutable::parse('2026-09-21 08:30'), 'فُعّل على الموقع صباحاً');

        $activation->refresh();
        $this->assertSame('2026-09-28 08:30:00', $activation->ends_at->format('Y-m-d H:i:s'));
        $this->assertTrue($activation->start_overridden);
    }

    public function test_company_funded_promotion_charges_the_company_the_lower_price(): void
    {
        $promotion = Promotion::create([
            'name' => 'عرض جديد', 'audience' => PromotionAudience::New, 'discount_type' => DiscountType::AmountOff,
            'discount_value' => 5000, 'funded_by' => PromotionFunding::Company,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $activation = $this->activations()->activate($this->account(), $this->plan(), ActivationKind::Partial7, promotion: $promotion);

        $this->assertSame(30000, $activation->final_price);
        $this->assertSame(-30000, $this->balanceOf($this->companyBox()->ledger_account_id));
        $this->assertSame(0, $this->balanceOf(Ledger::EXP_PROMO_DISCOUNT));
    }

    public function test_agent_funded_promotion_books_the_discount_as_an_expense(): void
    {
        $promotion = Promotion::create([
            'name' => 'عرض الوكيل', 'audience' => PromotionAudience::All, 'discount_type' => DiscountType::FixedPrice,
            'discount_value' => 30000, 'funded_by' => PromotionFunding::Agent,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $activation = $this->activations()->activate($this->account(), $this->plan(), ActivationKind::Full30, promotion: $promotion);

        $this->assertSame(30000, $activation->debt->balance);
        $this->assertSame(-35000, $this->balanceOf($this->companyBox()->ledger_account_id));
        $this->assertSame(5000, $this->balanceOf(Ledger::EXP_PROMO_DISCOUNT));
    }

    public function test_paid_thirty_day_activation_issues_a_receipt_immediately(): void
    {
        $account = $this->account();

        $activation = $this->activations()->activate($account, $this->plan('turbo'), ActivationKind::Full30, settlement: Settlement::Paid, moneyAccount: $this->cash());

        $this->assertSame(DebtStatus::Paid, $activation->debt->status);
        $this->assertSame(1, $account->payments()->count());
        $this->assertSame(65000, $this->balanceOf($this->cash()->ledger_account_id));
    }

    public function test_seven_day_activation_cannot_be_paid_upfront(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->activations()->activate($this->account(), $this->plan(), ActivationKind::Partial7, settlement: Settlement::Paid, moneyAccount: $this->cash());
    }

    public function test_voiding_an_activation_voids_its_debt_and_returns_the_company_balance(): void
    {
        $account = $this->account();
        $activation = $this->activations()->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->activations()->void($activation, 'سُجّل على الحساب الخطأ');

        $this->assertSame(DebtStatus::Voided, $activation->debt->fresh()->status);
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame(0, $this->balanceOf($this->companyBox()->ledger_account_id));
        $this->assertNull($account->fresh()->service_ends_at);
    }
}

<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\PromotionAudience;
use App\Enums\PromotionFunding;
use App\Models\Account;
use App\Models\Activation;
use App\Models\Promotion;
use App\Models\ServicePlan;
use App\Exceptions\BusinessRuleException;
use DateTimeInterface;
use Illuminate\Support\Collection;

class Pricing
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array{list_price: int, discount_amount: int, final_price: int, company_cost: int}
     */
    public function quote(ServicePlan $plan, ?Promotion $promotion = null, ?int $overridePrice = null): array
    {
        $list = $plan->price;

        if ($overridePrice !== null) {
            if ($overridePrice < 0 || $overridePrice > $list) {
                throw new BusinessRuleException('السعر اليدوي يجب أن يكون بين صفر وسعر الفئة.');
            }

            // A manual price is the agent's own discount: the company still takes the full price.
            return ['list_price' => $list, 'discount_amount' => $list - $overridePrice, 'final_price' => $overridePrice, 'company_cost' => $list];
        }

        if ($promotion === null) {
            return ['list_price' => $list, 'discount_amount' => 0, 'final_price' => $list, 'company_cost' => $list];
        }

        $discount = match ($promotion->discount_type) {
            DiscountType::FixedPrice => max(0, $list - $promotion->discount_value),
            DiscountType::AmountOff => $promotion->discount_value,
            DiscountType::PercentOff => $this->round(intdiv($list * $promotion->discount_value, 100)),
        };
        $discount = min($discount, $list);
        $final = $list - $discount;

        return [
            'list_price' => $list,
            'discount_amount' => $discount,
            'final_price' => $final,
            'company_cost' => $promotion->funded_by === PromotionFunding::Company ? $final : $list,
        ];
    }

    /**
     * Promotions that apply to this account and plan right now.
     *
     * @return Collection<int, Promotion>
     */
    public function eligiblePromotions(Account $account, ServicePlan $plan, DateTimeInterface $at): Collection
    {
        $isNew = ! Activation::where('account_id', $account->id)->where('status', DocumentStatus::Posted)->exists();

        return Promotion::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', $plan->id))
            ->whereIn('audience', [PromotionAudience::All, $isNew ? PromotionAudience::New : PromotionAudience::Existing])
            ->orderBy('name')
            ->get();
    }

    private function round(int $amount): int
    {
        $step = max(1, (int) $this->settings->get('pricing.discount_rounding'));

        return (int) (round($amount / $step) * $step);
    }
}

<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionAudience;
use App\Enums\PromotionFunding;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class Promotion extends Model
{
    protected function casts(): array
    {
        return [
            'audience' => PromotionAudience::class,
            'discount_type' => DiscountType::class,
            'funded_by' => PromotionFunding::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'plan_id');
    }
}

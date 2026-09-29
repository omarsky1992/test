<?php

namespace App\Models;

use App\Enums\PeriodType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class ActivationPeriod extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_void' => 'boolean',
        ];
    }

    public function activation(): BelongsTo
    {
        return $this->belongsTo(Activation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

<?php

namespace App\Models;

use App\Enums\DistributionKind;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class SettlementDistribution extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => DistributionKind::class,
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CompanySettlement::class, 'settlement_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_money_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id');
    }
}

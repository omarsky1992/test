<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class CompanySettlement extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'received_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'received_into_money_account_id');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(SettlementDistribution::class, 'settlement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

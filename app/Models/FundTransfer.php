<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class FundTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'transferred_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_money_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

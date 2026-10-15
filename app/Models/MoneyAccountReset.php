<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «تصفير رصيد البطاقة»: the documented adjustment that brought a card to zero. */
#[Unguarded]
class MoneyAccountReset extends Model
{
    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'voided_at' => 'immutable_datetime'];
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function txn(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'txn_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

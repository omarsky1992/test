<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class EmployeeAdvanceRepayment extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime', 'amount' => 'integer'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'advance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

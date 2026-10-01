<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class EmployeeAdvance extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AdvanceStatus::class,
            'advanced_at' => 'immutable_datetime',
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'balance' => 'integer',
        ];
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

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class, 'advance_id')->orderBy('paid_at');
    }
}

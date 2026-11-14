<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class AccountRenewal extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'previous_ends_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'amount' => 'integer',
            'decided_at' => 'immutable_datetime',
            'company_amount' => 'integer',
            'is_new' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /** How the company site says it was paid (its wallet log). `wallet` is from before the owner was read. */
    public const PAYMENT_SOURCES = [
        'card' => '💳 كارت',
        'wallet_partner' => '👛 محفظة الوكيل',
        'wallet_customer' => '📱 محفظة المشترك',
        'wallet' => '👛 محفظة',
    ];

    public const SOURCE_COLORS = ['card' => 'warning', 'wallet_partner' => 'info', 'wallet_customer' => 'success', 'wallet' => 'info'];

    /** What became of it, in words, with a colour: «دين ثانوي D-12», «بانتظار القرار»… */
    public function outcome(): array
    {
        return match (true) {
            $this->status === 'pending_debt' => ['7 أيام: بانتظار نعم/لا', 'warning'],
            $this->status === 'no_debt' => ['7 أيام: بدون دين (لا)', 'gray'],
            $this->status === 'debt_created' => ['دين ثانوي'.($this->debt?->number ? ' '.$this->debt->number : ''), 'danger'],
            $this->status === 'test' => ['تيست من المشترك: بدون دين', 'gray'],
            $this->status === 'no_price' => ['بدون دين: الفئة بلا سعر', 'gray'],
            $this->decision === 'debt' => ['دين أولي'.($this->decisionDebt?->number ? ' '.$this->decisionDebt->number : ''), 'danger'],
            $this->decision === 'cash' => ['نقد', 'success'],
            $this->decision === 'transfer' => ['مناقلة', 'info'],
            $this->decision === 'earlier' => ['تفعيل كامل (قبل القائمة)', 'gray'],
            default => ['تفعيل كامل: بانتظار القرار', 'warning'],
        };
    }

    public const OUTCOMES = [
        'waiting' => 'تفعيل كامل: بانتظار القرار',
        'pending_debt' => '7 أيام: بانتظار نعم/لا',
        'debt_created' => 'دين ثانوي',
        'no_debt' => '7 أيام: بدون دين',
        'primary' => 'دين أولي',
        'cash' => 'نقد',
        'transfer' => 'مناقلة',
        'test' => 'تيست من المشترك',
    ];

    public function decisionDebt(): BelongsTo
    {
        return $this->belongsTo(Debt::class, 'decision_debt_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

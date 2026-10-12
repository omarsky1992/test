<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «القائمة السوداء»: a subscriber flagged for internal follow-up (keeps breaking payment promises).
 * By the subscriber's id; it changes nothing on the subscription, the debts or the payments and
 * blocks nothing. A removed entry stays as history.
 */
#[Unguarded]
class BlacklistEntry extends Model
{
    public $timestamps = false;

    public const REASONS = [
        'late_payments' => 'تكرار عدم الالتزام بمواعيد التسديد',
        'broken_promises' => 'تكرار التأخر عن الموعد المتفق عليه',
        'other' => 'سبب آخر',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_type] ?? $this->reason_type;
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}

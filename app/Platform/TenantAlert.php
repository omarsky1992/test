<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A subscription alert already raised for a client (see SubscriptionAlerts). */
class TenantAlert extends Model
{
    protected $connection = 'platform';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ends_at' => 'immutable_datetime', 'seen_at' => 'immutable_datetime', 'days_before' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function label(): string
    {
        $what = $this->kind === 'trial' ? 'التجربة' : 'الاشتراك';

        return $this->days_before === 0 ? "{$what} ينتهي اليوم" : "{$what} ينتهي بعد {$this->days_before} ".($this->days_before === 1 ? 'يوم' : 'أيام');
    }
}

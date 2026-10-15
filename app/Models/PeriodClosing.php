<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A closed purchases or sales period with its final totals. */
#[Unguarded]
class PeriodClosing extends Model
{
    public $timestamps = false;

    public const SCOPES = ['purchases' => 'المشتريات', 'sales' => 'المبيعات'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'breakdown' => 'array'];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}

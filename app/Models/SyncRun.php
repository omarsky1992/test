<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class SyncRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['stats' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

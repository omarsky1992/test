<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class ImportRun extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['mapping' => 'array', 'stats' => 'array', 'finished_at' => 'immutable_datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class DeviceType extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

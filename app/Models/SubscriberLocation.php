<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where a subscriber's house is (saved on site) and its optional photo. */
#[Unguarded]
class SubscriberLocation extends Model
{
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'saved_at' => 'immutable_datetime',
            'photo_at' => 'immutable_datetime',
        ];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function coordinates(): string
    {
        return number_format($this->latitude, 7, '.', '').','.number_format($this->longitude, 7, '.', '');
    }

    /** Google Maps directions from wherever the employee is to the saved house. */
    public function directionsUrl(): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='.$this->coordinates();
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function saver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photo_by');
    }
}

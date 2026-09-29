<?php

namespace App\Services;

use App\Models\Setting;

class Settings
{
    public const DEFAULTS = [
        'activation.partial_days' => 7,
        'activation.full_days' => 30,
        'pricing.discount_rounding' => 250,
        'accounts.max_per_subscriber_warning' => 2,
        'company.name' => '[اسم الوكيل]',
        'company.address' => '',
        'company.phone' => '',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key): mixed
    {
        $this->cache ??= Setting::pluck('value', 'key')->all();

        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            Setting::whereKey($key)->delete();
        } else {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now(), 'updated_by' => auth()->id()]);
        }
        $this->cache = null;
    }

    public function partialDays(): int
    {
        return (int) $this->get('activation.partial_days');
    }

    public function fullDays(): int
    {
        return (int) $this->get('activation.full_days');
    }

    public function extensionDays(): int
    {
        return $this->fullDays() - $this->partialDays();
    }
}

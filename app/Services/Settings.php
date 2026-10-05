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
        'sync.enabled' => false,
        'sync.interval_minutes' => 60,
        'sync.base_url' => 'https://admin.ftth.iq',
        'sync.token_url' => 'https://sso.ftth.iq/auth/realms/Partners/protocol/openid-connect/token',
        'sync.client_id' => 'earthlink-portals',
        'sync.client_app' => '53d57a7f-3f89-4e9d-873b-3d071bc6dd9f',
        'sync.hierarchy_level' => '',
        'sync.detail_limit' => 200,
        'subscribers.expiring_days' => 7,
        'whatsapp.enabled' => false,
        'whatsapp.voice_enabled' => true,
        'whatsapp.unauthorized_message' => 'هذا الرقم غير مصرح له باستخدام النظام.',
        'whatsapp.duplicate_hours' => 12,
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

    /** «ينتهي قريباً»: a subscription ending within this many days. */
    public function expiringDays(): int
    {
        return max(1, (int) $this->get('subscribers.expiring_days'));
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

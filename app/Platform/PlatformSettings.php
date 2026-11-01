<?php

namespace App\Platform;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** The platform owner's settings, read on the clients' pages too (never a client's own settings). */
class PlatformSettings
{
    public const DEFAULTS = [
        'contact.name' => 'إدارة المنصة',
        'contact.phone' => '',
        'contact.message' => 'للتجديد تواصل مع إدارة المنصة.',
    ];

    public function get(string $key): string
    {
        try {
            $value = DB::connection('platform')->table('platform_settings')->where('key', $key)->value('value');
        } catch (QueryException) {
            $value = null;
        }
        $value = is_string($value) ? json_decode($value, true) : null;

        return is_string($value) ? $value : (self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, ?string $value): void
    {
        DB::connection('platform')->table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode((string) $value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );
    }
}

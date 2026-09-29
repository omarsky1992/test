<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials drawn locally, so the panel never calls an outside avatar service.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initial = mb_substr(trim((string) filament()->getNameForDefaultAvatar($record)), 0, 1) ?: '؟';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#0f766e"/>'
            .'<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="30" fill="#fff">'
            .htmlspecialchars($initial).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

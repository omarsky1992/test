<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * The colours a user can pick for their own interface.
 */
class Themes
{
    /** @var array<string, array{0: string, 1: string}> key => [Arabic name, swatch hex] */
    public const ALL = [
        'teal' => ['تيل', '#0f766e'],
        'blue' => ['أزرق', '#1d4ed8'],
        'violet' => ['بنفسجي', '#7c3aed'],
        'red' => ['أحمر', '#dc2626'],
        'green' => ['أخضر', '#16a34a'],
        'orange' => ['برتقالي', '#ea580c'],
        'pink' => ['وردي', '#db2777'],
    ];

    public static function palette(?string $key): array
    {
        return match ($key) {
            'blue' => Color::Blue,
            'violet' => Color::Violet,
            'red' => Color::Red,
            'green' => Color::Green,
            'orange' => Color::Orange,
            'pink' => Color::Pink,
            default => Color::Teal,
        };
    }
}

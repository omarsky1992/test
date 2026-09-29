<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActivationKind: string implements HasColor, HasLabel
{
    case Partial7 = 'partial_7';
    case Full30 = 'full_30';

    public function getLabel(): string
    {
        return match ($this) {
            self::Partial7 => '7 أيام',
            self::Full30 => '30 يوم',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Partial7 => 'warning',
            self::Full30 => 'info',
        };
    }
}

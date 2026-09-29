<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PeriodType: string implements HasColor, HasLabel
{
    case Initial7 = 'initial_7';
    case Initial30 = 'initial_30';
    case Extension23 = 'extension_23';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initial7 => '7 أيام',
            self::Initial30 => '30 يوم',
            self::Extension23 => 'إكمال 23 يوم',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initial7 => 'warning',
            self::Initial30 => 'info',
            self::Extension23 => 'success',
        };
    }
}

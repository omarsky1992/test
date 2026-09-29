<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DebtBucket: string implements HasColor, HasLabel
{
    case Secondary = 'secondary';
    case Primary = 'primary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Secondary => 'ثانوي',
            self::Primary => 'أولي',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Secondary => 'warning',
            self::Primary => 'info',
        };
    }
}

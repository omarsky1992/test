<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MoneyAccountKind: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Electronic = 'electronic';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'قاصة نقدية',
            self::Electronic => 'محفظة إلكترونية',
            self::Company => 'رصيد الشركة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Electronic => 'info',
            self::Company => 'warning',
        };
    }
}

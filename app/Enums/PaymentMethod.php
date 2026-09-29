<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Electronic = 'electronic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Electronic => 'إلكتروني',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Electronic => 'info',
        };
    }
}

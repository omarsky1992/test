<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DebtSource: string implements HasColor, HasLabel
{
    case Activation = 'activation';
    case Manual = 'manual';
    case Opening = 'opening';
    case DeviceSale = 'device_sale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Activation => 'تفعيل',
            self::Manual => 'يدوي',
            self::Opening => 'افتتاحي',
            self::DeviceSale => 'بيع جهاز',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Activation => 'info',
            self::Manual => 'gray',
            self::Opening => 'gray',
            self::DeviceSale => 'info',
        };
    }
}

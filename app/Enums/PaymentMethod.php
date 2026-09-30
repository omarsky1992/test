<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Electronic = 'electronic';
    case Card = 'card';
    case Bank = 'bank';
    case Other = 'other';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Electronic => 'إلكتروني',
            self::Card => 'بطاقة',
            self::Bank => 'تحويل مصرفي',
            self::Other => 'أخرى',
            self::Mixed => 'متعدد',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Electronic => 'info',
            self::Card => 'warning',
            self::Bank => 'gray',
            self::Other => 'gray',
            self::Mixed => 'primary',
        };
    }
}

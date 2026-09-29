<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DistributionKind: string implements HasColor, HasLabel
{
    case ZoneFund = 'zone_fund';
    case Partner = 'partner';
    case Salary = 'salary';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ZoneFund => 'إلى الزون',
            self::Partner => 'شريك',
            self::Salary => 'راتب',
            self::Other => 'أخرى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ZoneFund => 'success',
            self::Partner => 'info',
            self::Salary => 'info',
            self::Other => 'gray',
        };
    }
}

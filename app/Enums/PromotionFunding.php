<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PromotionFunding: string implements HasColor, HasLabel
{
    case Company = 'company';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Company => 'الشركة',
            self::Agent => 'الوكيل',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Company => 'info',
            self::Agent => 'warning',
        };
    }
}

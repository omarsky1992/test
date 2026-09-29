<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasColor, HasLabel
{
    case FixedPrice = 'fixed_price';
    case AmountOff = 'amount_off';
    case PercentOff = 'percent_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::FixedPrice => 'سعر ثابت',
            self::AmountOff => 'خصم مبلغ',
            self::PercentOff => 'خصم نسبة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FixedPrice => 'info',
            self::AmountOff => 'info',
            self::PercentOff => 'info',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentType: string implements HasColor, HasLabel
{
    case DebtPayment = 'debt_payment';
    case Advance = 'advance';

    public function getLabel(): string
    {
        return match ($this) {
            self::DebtPayment => 'دفع دين',
            self::Advance => 'دفع مقدم',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DebtPayment => 'success',
            self::Advance => 'info',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentType: string implements HasColor, HasLabel
{
    case DebtPayment = 'debt_payment';
    /** «تفعيل نقدي»: the cash price of an activation; pays the open debts first, never becomes credit. */
    case Activation = 'activation';
    /** Old receipts only: an advance kept as credit. No screen or command records one any more. */
    case Advance = 'advance';

    /** What a receipt can be recorded as today. */
    public static function current(): array
    {
        return [self::DebtPayment->value => self::DebtPayment->getLabel(), self::Activation->value => self::Activation->getLabel()];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DebtPayment => 'دفع دين',
            self::Activation => 'تفعيل نقدي',
            self::Advance => 'دفع مقدم (قديم)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DebtPayment => 'success',
            self::Activation, self::Advance => 'info',
        };
    }
}

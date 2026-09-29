<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FollowUpOutcome: string implements HasColor, HasLabel
{
    case NoAnswer = 'no_answer';
    case PromisedToPay = 'promised_to_pay';
    case WillPayToday = 'will_pay_today';
    case Refused = 'refused';
    case WrongNumber = 'wrong_number';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoAnswer => 'لم يرد',
            self::PromisedToPay => 'وعد بالدفع',
            self::WillPayToday => 'سيدفع اليوم',
            self::Refused => 'رفض',
            self::WrongNumber => 'رقم خطأ',
            self::Other => 'أخرى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NoAnswer => 'gray',
            self::PromisedToPay => 'info',
            self::WillPayToday => 'success',
            self::Refused => 'danger',
            self::WrongNumber => 'danger',
            self::Other => 'gray',
        };
    }
}

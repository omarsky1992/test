<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Settlement: string implements HasColor, HasLabel
{
    case Debt = 'debt';
    case Paid = 'paid';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Debt => 'آجل (دين)',
            self::Paid => 'مدفوع',
            self::Credit => 'من الرصيد المقدم',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Debt => 'warning',
            self::Paid => 'success',
            self::Credit => 'info',
        };
    }
}

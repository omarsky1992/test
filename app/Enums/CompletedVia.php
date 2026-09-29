<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompletedVia: string implements HasColor, HasLabel
{
    case Payment = 'payment';
    case Transfer = 'transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Payment => 'بالتسديد',
            self::Transfer => 'بالمناقلة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Payment => 'success',
            self::Transfer => 'info',
        };
    }
}

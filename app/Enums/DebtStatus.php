<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DebtStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'مفتوح',
            self::Partial => 'مسدد جزئياً',
            self::Paid => 'مسدد',
            self::Voided => 'ملغى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
            self::Voided => 'gray',
        };
    }
}

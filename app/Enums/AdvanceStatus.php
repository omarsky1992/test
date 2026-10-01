<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdvanceStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public static function for(int $amount, int $paid): self
    {
        return match (true) {
            $paid <= 0 => self::Unpaid,
            $paid >= $amount => self::Paid,
            default => self::Partial,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'غير مسددة',
            self::Partial => 'مسددة جزئياً',
            self::Paid => 'مسددة بالكامل',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
        };
    }
}

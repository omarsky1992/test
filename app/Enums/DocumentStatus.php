<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasColor, HasLabel
{
    case Posted = 'posted';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Posted => 'مرحّل',
            self::Voided => 'ملغى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Voided => 'danger',
        };
    }
}

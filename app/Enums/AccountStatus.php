<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'فعّال',
            self::Suspended => 'موقوف',
            self::Closed => 'مغلق',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Closed => 'gray',
        };
    }
}

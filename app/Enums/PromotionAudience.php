<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PromotionAudience: string implements HasColor, HasLabel
{
    case New = 'new';
    case Existing = 'existing';
    case All = 'all';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'المشتركون الجدد',
            self::Existing => 'المشتركون الحاليون',
            self::All => 'الجميع',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Existing => 'info',
            self::All => 'gray',
        };
    }
}

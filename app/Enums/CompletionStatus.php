<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompletionStatus: string implements HasColor, HasLabel
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotRequired => 'لا يحتاج',
            self::Pending => 'بانتظار الإكمال',
            self::Completed => 'مكتمل',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Pending => 'warning',
            self::Completed => 'success',
        };
    }
}

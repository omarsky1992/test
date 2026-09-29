<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FollowUpChannel: string implements HasColor, HasLabel
{
    case Call = 'call';
    case Whatsapp = 'whatsapp';
    case Visit = 'visit';
    case Sms = 'sms';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'اتصال',
            self::Whatsapp => 'واتساب',
            self::Visit => 'زيارة',
            self::Sms => 'رسالة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Call => 'info',
            self::Whatsapp => 'success',
            self::Visit => 'gray',
            self::Sms => 'gray',
        };
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Actions\Operations;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'لوحة التحكم';

    protected function getHeaderActions(): array
    {
        return [
            Operations::pay('newPayment')->label('قبض'),
            Operations::activate('newActivation')->label('تفعيل جديد'),
        ];
    }
}

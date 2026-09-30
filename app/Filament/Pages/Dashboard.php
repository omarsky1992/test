<?php

namespace App\Filament\Pages;

use App\Filament\Actions\Operations;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'لوحة التحكم';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('الفترة')
                ->options([
                    'today' => 'اليوم',
                    'week' => 'آخر 7 أيام',
                    'month' => 'هذا الشهر',
                    'last_month' => 'الشهر الماضي',
                    'custom' => 'فترة مخصصة',
                ])
                ->default('today')
                ->selectablePlaceholder(false)
                ->live(),
            DatePicker::make('from')->label('من')->visible(fn (Get $get) => $get('period') === 'custom')->live(),
            DatePicker::make('to')->label('إلى')->visible(fn (Get $get) => $get('period') === 'custom')->live(),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 2];
    }

    protected function getHeaderActions(): array
    {
        return [
            Operations::pay('newPayment')->label('قبض'),
            Operations::activate('newActivation')->label('تفعيل جديد'),
        ];
    }
}

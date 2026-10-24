<?php

namespace App\Filament\Pages;

use App\Filament\Actions\Operations;
use App\Filament\Resources\SecondarySales\SecondarySaleResource;
use Filament\Actions\ActionGroup;
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

    public function mount(): void
    {
        // Employees, and the admin who switched to the employee interface, land on their own home.
        if (! self::opensForCurrentUser()) {
            $this->redirect(EmployeeHome::getUrl());
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user() !== null && self::opensForCurrentUser();
    }

    /** The admin in the admin interface, or an employee the admin gave «لوحة التحكم». */
    public static function opensForCurrentUser(): bool
    {
        $user = auth()->user();

        return $user->isAdmin() ? ! $user->usesEmployeeUi() : $user->can('dashboard.view');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('الفترة')
                ->options([
                    'today' => 'اليوم',
                    'open' => 'منذ آخر تصفير',
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
            ActionGroup::make([...AccountingPeriodsPage::closeActions(), SecondarySaleResource::resetAction()])
                ->label('تصفير المبيعات والمشتريات')->icon('heroicon-o-arrow-path')->color('gray')->button()
                ->visible(fn () => auth()->user()->can('periods.close') || auth()->user()->can('secondary_sales.reset')),
        ];
    }
}

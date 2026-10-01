<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\Widget;

/**
 * The total balance (cash boxes, wallets, employees' custody and the company balance) with a
 * hide/show toggle, and beside it, for information only, what is owed: debts and employee advances.
 */
class BalanceOverview extends Widget
{
    protected string $view = 'filament.widgets.balance-overview';

    protected static ?int $sort = 1;

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public bool $hidden = false;

    public function mount(): void
    {
        $this->hidden = (bool) session('dashboard.hide_balance', false);
    }

    public function toggle(): void
    {
        $this->hidden = ! $this->hidden;
        session(['dashboard.hide_balance' => $this->hidden]);
    }

    protected function getViewData(): array
    {
        $b = app(ReportService::class)->balances();

        return [
            'total' => $b['cash'] + $b['electronic'] + $b['company'] + $b['custody'],
            'custody' => $b['custody'],
            'custodyHolders' => $b['custody_holders'],
            'advances' => $b['advances'],
            'advancesCount' => $b['advances_count'],
            'showEmployees' => auth()->user()->can('employees.view'),
            // Each card opens its details, for those allowed to see them.
            'links' => [
                'boxes' => self::url(\App\Filament\Resources\MoneyAccounts\MoneyAccountResource::class),
                'secondary' => self::url(\App\Filament\Resources\Debts\DebtResource::class, ['tab' => 'secondary']),
                'primary' => self::url(\App\Filament\Resources\Debts\DebtResource::class, ['tab' => 'primary']),
                'custody' => self::url(\App\Filament\Resources\Employees\EmployeeResource::class),
                'advances' => self::url(\App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource::class),
            ],
            'cash' => $b['cash'],
            'electronic' => $b['electronic'],
            'company' => $b['company'],
            'secondary' => $b['secondary'],
            'secondaryAccounts' => $b['secondary_accounts'],
            'primary' => $b['primary'],
            'primaryAccounts' => $b['primary_accounts'],
        ];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function url(string $resource, array $parameters = []): ?string
    {
        return $resource::canViewAny() ? $resource::getUrl('index', $parameters) : null;
    }
}

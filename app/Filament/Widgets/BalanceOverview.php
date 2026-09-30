<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\Widget;

/**
 * The total balance (cash boxes, wallets and the company balance) with a hide/show toggle,
 * and the debts shown beside it for information only: they are money owed, not money held.
 */
class BalanceOverview extends Widget
{
    protected string $view = 'filament.widgets.balance-overview';

    protected static ?int $sort = 1;

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
            'total' => $b['cash'] + $b['electronic'] + $b['company'],
            'cash' => $b['cash'],
            'electronic' => $b['electronic'],
            'company' => $b['company'],
            'secondary' => $b['secondary'],
            'secondaryAccounts' => $b['secondary_accounts'],
            'primary' => $b['primary'],
            'primaryAccounts' => $b['primary_accounts'],
        ];
    }
}

<?php

namespace App\Filament\Resources\MoneyAccounts\Pages;

use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListMoneyAccounts extends ListRecords
{
    protected static string $resource = MoneyAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [MoneyAccountResource::transferAction(), MoneyAccountResource::newBoxAction()];
    }
}

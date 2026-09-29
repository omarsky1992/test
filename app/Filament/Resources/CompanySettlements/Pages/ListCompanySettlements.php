<?php

namespace App\Filament\Resources\CompanySettlements\Pages;

use App\Filament\Resources\CompanySettlements\CompanySettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListCompanySettlements extends ListRecords
{
    protected static string $resource = CompanySettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [CompanySettlementResource::recordAction()];
    }
}

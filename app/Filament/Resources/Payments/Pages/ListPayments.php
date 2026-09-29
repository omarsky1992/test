<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [Operations::pay('newPayment')->label('قبض جديد')];
    }
}

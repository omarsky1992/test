<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'السندات';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return PaymentResource::buildTable($table, showSubscriber: false)->filters([]);
    }
}

<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Filament\Resources\Debts\DebtResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DebtsRelationManager extends RelationManager
{
    protected static string $relationship = 'debts';

    protected static ?string $title = 'الديون';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return DebtResource::buildTable($table, showSubscriber: false)->filters([]);
    }
}

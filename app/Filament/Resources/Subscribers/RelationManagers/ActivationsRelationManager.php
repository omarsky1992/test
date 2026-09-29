<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Filament\Resources\Activations\ActivationResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ActivationsRelationManager extends RelationManager
{
    protected static string $relationship = 'activations';

    protected static ?string $title = 'التفعيلات';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return ActivationResource::buildTable($table, showSubscriber: false)->filters([]);
    }
}

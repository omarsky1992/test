<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Filament\Resources\Accounts\AccountResource;
use App\Services\SubscriberService;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    protected static ?string $title = 'الحسابات';

    protected static ?string $modelLabel = 'حساب';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return AccountResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return AccountResource::buildTable($table, showSubscriber: false)
            ->filters([])
            ->headerActions([
                CreateAction::make()->label('إضافة حساب (بيت آخر)')
                    ->visible(fn () => auth()->user()->can('accounts.create'))
                    ->using(fn (array $data) => app(SubscriberService::class)->createAccount($this->getOwnerRecord(), $data)),
            ]);
    }
}

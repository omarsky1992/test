<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    protected static ?string $title = 'الاتصالات';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y/m/d H:i'),
                TextColumn::make('account.username')->label('اليوزر')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('channel')->label('الوسيلة')->badge(),
                TextColumn::make('outcome')->label('النتيجة')->badge(),
                TextColumn::make('promised_at')->label('موعد الدفع')->dateTime('Y/m/d H:i')->placeholder('—'),
                TextColumn::make('next_follow_up_at')->label('الاتصال التالي')->dateTime('Y/m/d H:i')->placeholder('—'),
                TextColumn::make('note')->label('ملاحظة')->placeholder('—')->wrap(),
                TextColumn::make('creator.name')->label('الموظف'),
            ]);
    }
}

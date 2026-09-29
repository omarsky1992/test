<?php

namespace App\Filament\Resources\FollowUps;

use App\Enums\CompletionStatus;
use App\Enums\DocumentStatus;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Activations\ActivationResource;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Activation;
use App\Models\FollowUp;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The follow-up and call list: 7-day activations still waiting for payment or transfer.
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = Activation::class;

    protected static ?string $slug = 'follow-ups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'المتابعة والاتصال';

    protected static ?string $modelLabel = 'متابعة';

    protected static ?string $pluralModelLabel = 'المتابعة والاتصال';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('completion_status', CompletionStatus::Pending)
            ->where('status', DocumentStatus::Posted);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('ends_at', '<', now()->endOfDay())->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'account', 'plan', 'debt'])
                ->addSelect(['last_outcome' => FollowUp::select('outcome')->whereColumn('follow_ups.activation_id', 'activations.id')->latest('id')->limit(1)])
                ->addSelect(['next_follow_up_at' => FollowUp::select('next_follow_up_at')->whereColumn('follow_ups.activation_id', 'activations.id')->latest('id')->limit(1)]))
            ->defaultSort('ends_at')
            ->columns([
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (Activation $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('subscriber.phone')->label('الهاتف')->extraAttributes(['dir' => 'ltr'])
                    ->url(fn (Activation $r) => 'tel:'.$r->subscriber->phone),
                TextColumn::make('account.username')->label('اليوزر')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('plan.name_ar')->label('الفئة'),
                TextColumn::make('debt.balance')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format((int) $state, false)),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('time_status')->label('الوضع')->badge()
                    ->state(fn (Activation $r) => ActivationResource::timeStatus($r)[0])
                    ->color(fn (Activation $r) => ActivationResource::timeStatus($r)[1]),
                TextColumn::make('last_outcome')->label('آخر اتصال')->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => \App\Enums\FollowUpOutcome::tryFrom($state)?->getLabel())
                    ->color(fn ($state) => \App\Enums\FollowUpOutcome::tryFrom((string) $state)?->getColor()),
                TextColumn::make('next_follow_up_at')->label('الاتصال التالي')->dateTime('m/d H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('whatsapp')->label('واتساب')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')->iconButton()
                    ->url(fn (Activation $r) => 'https://wa.me/'.$r->subscriber->phone_normalized)->openUrlInNewTab(),
                Operations::followUp(),
                Operations::pay(),
                Operations::transfer(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFollowUps::route('/')];
    }
}

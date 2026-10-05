<?php

namespace App\Filament\Resources\WhatsappOutbox;

use App\Filament\Resources\WhatsappOutbox\Pages\ListWhatsappOutbox;
use App\Models\WhatsappOutbox;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * سجل الإرسال: every message the system sent, or will send, from the linked phone.
 */
class WhatsappOutboxResource extends Resource
{
    protected static ?string $model = WhatsappOutbox::class;

    protected static ?string $slug = 'whatsapp-outbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'رسالة صادرة';

    protected static ?string $pluralModelLabel = 'سجل الإرسال';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('whatsapp.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['account.subscriber', 'creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('أُضيفت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('kind')->label('النوع')->badge()->formatStateUsing(fn (string $state) => WhatsappOutbox::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => str_starts_with($state, 'alert_') ? 'warning' : 'info'),
                TextColumn::make('to_phone')->label('إلى')->extraAttributes(['dir' => 'ltr'])->searchable()
                    ->description(fn (WhatsappOutbox $r) => $r->account?->subscriber?->full_name),
                TextColumn::make('body')->label('النص')->limit(60)->tooltip(fn (WhatsappOutbox $r) => $r->body)->searchable(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn (string $state) => WhatsappOutbox::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['sent' => 'success', 'failed' => 'danger', 'skipped' => 'gray'][$state] ?? 'warning')
                    ->description(fn (WhatsappOutbox $r) => $r->error ? mb_strimwidth($r->error, 0, 80, '…') : null),
                TextColumn::make('sent_at')->label('أُرسلت')->dateTime('Y/m/d H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(WhatsappOutbox::STATUSES),
                SelectFilter::make('kind')->label('النوع')->options(WhatsappOutbox::KINDS),
            ])
            ->recordActions([
                Action::make('retry')->label('إعادة المحاولة')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn (WhatsappOutbox $r) => $r->status === 'failed')
                    ->action(fn (WhatsappOutbox $r) => $r->update(['status' => 'pending', 'attempts' => 0, 'error' => null])),
                Action::make('cancel')->label('إلغاء')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (WhatsappOutbox $r) => $r->status === 'pending')->requiresConfirmation()
                    ->action(fn (WhatsappOutbox $r) => $r->update(['status' => 'skipped'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWhatsappOutbox::route('/')];
    }
}

<?php

namespace App\Filament\Resources\WhatsappBroadcasts;

use App\Filament\Resources\WhatsappBroadcasts\Pages\ListWhatsappBroadcasts;
use App\Models\WhatsappBroadcast;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * سجل الرسائل الجماعية: what was sent, by whom, when, to whom, and each recipient's status.
 */
class WhatsappBroadcastResource extends Resource
{
    protected static ?string $model = WhatsappBroadcast::class;

    protected static ?string $slug = 'whatsapp-broadcasts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'رسالة جماعية';

    protected static ?string $pluralModelLabel = 'سجل الرسائل الجماعية';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('whatsapp.broadcast');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $count = fn (string $status) => fn (Builder $q) => $q->where('status', $status);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('creator')
                ->withCount(['messages as sent_count' => $count('sent'), 'messages as pending_count' => $count('pending'), 'messages as failed_count' => $count('failed')]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('creator.name')->label('المرسل')->placeholder('—'),
                TextColumn::make('body')->label('النص')->limit(70)->tooltip(fn (WhatsappBroadcast $r) => $r->body)->searchable(),
                IconColumn::make('image_path')->label('صورة')->boolean()->state(fn (WhatsappBroadcast $r) => $r->image_path !== null),
                TextColumn::make('recipients')->label('المستلمون')->alignCenter()
                    ->description(fn (WhatsappBroadcast $r) => $r->skipped ? "بلا رقم: {$r->skipped}" : null),
                TextColumn::make('sent_count')->label('أُرسلت')->color('success')->alignCenter(),
                TextColumn::make('pending_count')->label('بالانتظار')->color('warning')->alignCenter(),
                TextColumn::make('failed_count')->label('فشلت')->color('danger')->alignCenter(),
            ])
            ->recordActions([
                Action::make('recipients')->label('المستلمون')->icon(Heroicon::OutlinedUsers)->color('gray')
                    ->modalHeading('المستلمون وحالة كل رسالة')->modalWidth('3xl')
                    ->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (WhatsappBroadcast $record) => view('filament.broadcasts.recipients', [
                        'broadcast' => $record,
                        'messages' => $record->messages()->with('account.subscriber')->orderBy('id')->get(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWhatsappBroadcasts::route('/')];
    }
}

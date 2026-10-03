<?php

namespace App\Filament\Resources\WhatsappMessages;

use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Models\WhatsappMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use UnitEnum;

/**
 * سجل عمليات واتساب: every message received, what it was understood as, what was done and the reply.
 */
class WhatsappMessageResource extends Resource
{
    protected static ?string $model = WhatsappMessage::class;

    protected static ?string $slug = 'whatsapp-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $pluralModelLabel = 'سجل عمليات واتساب';

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
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'number']))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('from_phone')->label('الرقم')->searchable()->extraAttributes(['dir' => 'ltr'])
                    ->description(fn (WhatsappMessage $r) => $r->number?->label),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('—'),
                TextColumn::make('type')->label('النوع')->formatStateUsing(fn (string $state) => ['text' => 'نص', 'audio' => 'صوت'][$state] ?? $state),
                TextColumn::make('body')->label('الأمر')->limit(50)->searchable(['body', 'transcript'])
                    ->state(fn (WhatsappMessage $r) => $r->body ?? $r->transcript)->tooltip(fn (WhatsappMessage $r) => $r->body ?? $r->transcript),
                TextColumn::make('intent')->label('المفهوم')->badge()->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => [
                        'activate' => 'تفعيل', 'payment' => 'قبض', 'purchase' => 'مشتريات', 'void_debt' => 'مسح دين',
                        'query' => 'استعلام', 'clarify' => 'توضيح', 'unknown' => 'غير مفهوم',
                    ][$state] ?? $state),
                TextColumn::make('status')->label('النتيجة')->badge()
                    ->formatStateUsing(fn (string $state) => WhatsappMessage::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'done' => 'success', 'clarify' => 'warning', 'unauthorized', 'denied', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('reply')->label('الرد')->limit(50)->tooltip(fn (WhatsappMessage $r) => $r->reply)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('النتيجة')->options(WhatsappMessage::STATUSES),
                SelectFilter::make('user_id')->label('المستخدم')->relationship('user', 'name'),
            ])
            ->recordActions([
                Action::make('details')->label('التفاصيل')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading('تفاصيل الرسالة')
                    ->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (WhatsappMessage $record): View => view('filament.whatsapp.message', ['message' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWhatsappMessages::route('/')];
    }
}

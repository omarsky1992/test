<?php

namespace App\Filament\Resources\CardAccounts;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CardAccounts\Pages\ListCardOperations;
use App\Filament\Resources\EmployeeTransfers\EmployeeTransferResource;
use App\Models\FundTransfer;
use App\Services\InternalTransfers;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The transfers between cards (and, below them, the resets), with cancelling by reverse entry. */
class CardOperationsResource extends Resource
{
    protected static ?string $model = FundTransfer::class;

    protected static ?string $slug = 'card-operations';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'مناقلة بطاقات';

    protected static ?string $pluralModelLabel = 'سجل مناقلات البطاقات والتصفير';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('cards.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', 'card')->with(['from', 'to', 'creator', 'voider']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading('المناقلات بين البطاقات')
            ->defaultSort('transferred_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable()->weight('bold'),
                TextColumn::make('transferred_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('from.name')->label('من'),
                TextColumn::make('to.name')->label('إلى'),
                TextColumn::make('amount')->label('المبلغ')->weight('bold')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('reference')->label('المرجع')->placeholder('—'),
                TextColumn::make('notes')->label('ملاحظات')->placeholder('—')->limit(50),
                TextColumn::make('creator.name')->label('نفّذها'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->description(fn (FundTransfer $r) => $r->voided_at ? 'ألغاها '.($r->voider?->name ?? '—').': '.$r->void_reason : null),
            ])
            ->filters([
                Filter::make('period')->label('الفترة')->schema([DatePicker::make('from')->label('من'), DatePicker::make('until')->label('إلى')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('transferred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('transferred_at', '<', now()->parse($d)->addDay()))),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (FundTransfer $r) => $r->status === DocumentStatus::Posted && auth()->user()->can('cards.void'))
                    ->requiresConfirmation()->modalDescription('تُلغى بقيد عكسي وتبقى في السجل.')
                    ->schema([TextInput::make('reason')->label('السبب')->required()->maxLength(300)])
                    ->action(fn (FundTransfer $record, array $data, Action $action) => EmployeeTransferResource::run($action,
                        fn () => app(InternalTransfers::class)->void($record, $data['reason']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCardOperations::route('/')];
    }
}

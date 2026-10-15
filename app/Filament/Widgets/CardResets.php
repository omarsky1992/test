<?php

namespace App\Filament\Widgets;

use App\Enums\DocumentStatus;
use App\Filament\Resources\EmployeeTransfers\EmployeeTransferResource;
use App\Models\MoneyAccountReset;
use App\Services\InternalTransfers;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** «تصفير الأرصدة»: every documented card reset, kept even after it is cancelled. */
class CardResets extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تصفير أرصدة البطاقات';

    public static function canView(): bool
    {
        return auth()->user()->can('cards.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => MoneyAccountReset::query()->with(['moneyAccount', 'creator', 'voider'])->latest('id'))
            ->columns([
                TextColumn::make('number')->label('الرقم')->weight('bold'),
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y/m/d H:i'),
                TextColumn::make('moneyAccount.name')->label('البطاقة'),
                TextColumn::make('amount')->label('الرصيد قبل التصفير')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('reason')->label('السبب')->wrap(),
                TextColumn::make('creator.name')->label('نفّذه'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->description(fn (MoneyAccountReset $r) => $r->voided_at ? 'ألغاه '.($r->voider?->name ?? '—').': '.$r->void_reason : null),
            ])
            ->recordActions([
                Action::make('voidReset')->label('إلغاء')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (MoneyAccountReset $r) => $r->status === DocumentStatus::Posted && auth()->user()->can('cards.void'))
                    ->requiresConfirmation()->modalDescription('يرجع الرصيد كما كان بقيد عكسي، ويبقى التصفير في السجل.')
                    ->schema([TextInput::make('reason')->label('السبب')->required()->maxLength(300)])
                    ->action(fn (MoneyAccountReset $record, array $data, Action $action) => EmployeeTransferResource::run($action,
                        fn () => app(InternalTransfers::class)->voidReset($record, $data['reason']))),
            ]);
    }
}

<?php

namespace App\Filament\Resources\EmployeeTransfers;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\EmployeeTransfers\Pages\ListEmployeeTransfers;
use App\Models\FundTransfer;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Services\InternalTransfers;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * «المناقلات بين حسابات الموظفين»: money moved from one employee's custody to another's. Not income,
 * not an expense, nothing to do with subscribers' debts.
 */
class EmployeeTransferResource extends Resource
{
    protected static ?string $model = FundTransfer::class;

    protected static ?string $slug = 'employee-transfers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'مناقلة بين موظفين';

    protected static ?string $pluralModelLabel = 'مناقلات الموظفين';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('custody_transfers.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', 'custody')->with(['from.user', 'to.user', 'creator', 'voider']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('transferred_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('رقم العملية')->searchable()->weight('bold'),
                TextColumn::make('transferred_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('from.holder_name')->label('من')->state(fn (FundTransfer $r) => $r->from?->user?->name ?? $r->from?->name),
                TextColumn::make('to.holder_name')->label('إلى')->state(fn (FundTransfer $r) => $r->to?->user?->name ?? $r->to?->name),
                TextColumn::make('amount')->label('المبلغ')->weight('bold')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('notes')->label('ملاحظات')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('creator.name')->label('نفّذها'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->description(fn (FundTransfer $r) => $r->voided_at ? 'ألغاها '.($r->voider?->name ?? '—').': '.$r->void_reason : null),
            ])
            ->filters([
                SelectFilter::make('employee')->label('الموظف')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                        ->whereHas('from', fn ($m) => $m->where('user_id', $id))->orWhereHas('to', fn ($m) => $m->where('user_id', $id))))),
                Filter::make('period')->label('الفترة')->schema([
                    DatePicker::make('from')->label('من'),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('transferred_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->where('transferred_at', '<', now()->parse($d)->addDay()))),
                SelectFilter::make('status')->label('الحالة')->options(DocumentStatus::class),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (FundTransfer $r) => $r->status === DocumentStatus::Posted && auth()->user()->can('custody_transfers.void'))
                    ->requiresConfirmation()->modalDescription('تُلغى بقيد عكسي: يرجع المبلغ للموظف المحوِّل وتبقى العملية في السجل.')
                    ->schema([TextInput::make('reason')->label('السبب')->required()->maxLength(300)])
                    ->action(function (FundTransfer $record, array $data, Action $action) {
                        self::run($action, fn () => app(InternalTransfers::class)->void($record, $data['reason']));
                    }),
            ]);
    }

    public static function createAction(): Action
    {
        $employees = fn () => User::where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' – '.Money::format(app(EmployeeFinance::class)->custodyBalance($u))]);

        return Action::make('newEmployeeTransfer')->label('مناقلة بين موظفين')->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => auth()->user()->can('custody_transfers.create'))
            ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
            ->schema([
                Hidden::make('idempotency_key'),
                Select::make('from')->label('من حساب الموظف')->options($employees)->required()->searchable(),
                Select::make('to')->label('إلى حساب الموظف')->options($employees)->required()->searchable()->different('from'),
                TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                Textarea::make('notes')->label('ملاحظات')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Action $action) {
                self::run($action, fn () => app(InternalTransfers::class)->betweenEmployees(
                    User::findOrFail($data['from']), User::findOrFail($data['to']), (int) $data['amount'], $data['notes'] ?? null, $data['idempotency_key'] ?? null,
                ));
            });
    }

    public static function run(Action $action, \Closure $callback): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title('تم الحفظ'.(isset($result->number) ? " ({$result->number})" : ''))->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeTransfers::route('/')];
    }
}

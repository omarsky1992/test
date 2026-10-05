<?php

namespace App\Filament\Resources\HandoverRequests;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Actions\EmployeeActions;
use App\Filament\Resources\HandoverRequests\Pages\ListHandoverRequests;
use App\Models\CustodyHandoverRequest;
use App\Models\MoneyAccount;
use App\Services\EmployeeFinance;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Employees' «سلّمت عهدتي» requests, confirmed or refused by whoever settles custody.
 */
class HandoverRequestResource extends Resource
{
    protected static ?string $model = CustodyHandoverRequest::class;

    protected static ?string $slug = 'handover-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'طلب تسليم';

    protected static ?string $pluralModelLabel = 'طلبات تسليم العهدة';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('custody.settle');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CustodyHandoverRequest::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'resolver']))
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('requested_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('user.name')->label('الموظف')->weight('bold'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('current_custody')->label('عهدته الآن')
                    ->state(fn (CustodyHandoverRequest $r) => app(EmployeeFinance::class)->custodyBalance($r->user))
                    ->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state) => CustodyHandoverRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('notes')->label('ملاحظة الموظف')->limit(40)->placeholder('—'),
                TextColumn::make('resolver.name')->label('عالجه')->placeholder('—')
                    ->description(fn (CustodyHandoverRequest $r) => $r->resolution_note),
            ])
            ->recordActions([
                Action::make('approve')->label('تأكيد الاستلام')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CustodyHandoverRequest $r) => $r->status === 'pending')
                    ->fillForm(fn (CustodyHandoverRequest $r) => [
                        'amount' => $r->amount,
                        'money_account_id' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->where('is_active', true)->orderBy('id')->value('id'),
                    ])
                    ->schema([
                        TextInput::make('amount')->label('المبلغ المستلم')->integer()->minValue(1)->suffix('د.ع')->required(),
                        Select::make('money_account_id')->label('استُلم في')->options(fn () => EmployeeActions::boxes())->required(),
                    ])
                    ->action(function (array $data, CustodyHandoverRequest $record, Action $action) {
                        try {
                            app(EmployeeFinance::class)->approveHandover($record, MoneyAccount::findOrFail($data['money_account_id']), (int) $data['amount']);
                            Notification::make()->success()->title('تم استلام العهدة')->send();
                        } catch (BusinessRuleException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
                Action::make('reject')->label('رفض')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (CustodyHandoverRequest $r) => $r->status === 'pending')
                    ->schema([Textarea::make('reason')->label('السبب')->required()->rows(2)])
                    ->action(function (array $data, CustodyHandoverRequest $record) {
                        app(EmployeeFinance::class)->rejectHandover($record, $data['reason']);
                        Notification::make()->success()->title('رُفض الطلب')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHandoverRequests::route('/')];
    }
}

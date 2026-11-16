<?php

namespace App\Filament\Widgets;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\MoneyAccountReset;
use App\Services\InternalTransfers;
use App\Services\ReportService;
use App\Services\Settings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * The total balance (cash boxes, wallets, employees' custody and the company balance) with a
 * hide/show toggle, and beside it, for information only, what is owed: debts and employee advances.
 */
class BalanceOverview extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.balance-overview';

    protected static ?int $sort = 1;

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public bool $hidden = false;

    public function mount(): void
    {
        $this->hidden = (bool) session('dashboard.hide_balance', false);
    }

    public function toggle(): void
    {
        $this->hidden = ! $this->hidden;
        session(['dashboard.hide_balance' => $this->hidden]);
    }

    /**
     * «تصفير الرصيد الكلي» (admin): every box in the total gets a documented, reversible reset
     * (`InternalTransfers::resetAllBalances`), after typing «تصفير».
     */
    public function resetBalancesAction(): Action
    {
        return Action::make('resetBalances')->label('تصفير الرصيد الكلي')->icon('heroicon-o-arrow-path')->color('danger')->size('sm')
            ->visible(fn () => auth()->user()->isAdmin())
            ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
            ->modalHeading('تصفير الرصيد الكلي')
            ->schema([
                Hidden::make('idempotency_key'),
                Text::make(function () {
                    $b = app(ReportService::class)->balances();

                    return 'الرصيد الكلي الآن: '.Money::format($b['cash'] + $b['electronic'] + $b['company'] + $b['custody'])
                        .' (القاصة '.Money::format($b['cash']).'، المحافظ والبطاقات '.Money::format($b['electronic']).'، رصيد الشركة '.Money::format($b['company']).'، عهد الموظفين '.Money::format($b['custody']).'). '
                        .'كل صندوق عليه رصيد يتصفّر بتسوية موثقة برقم، وتبقى حركاته السابقة، ويمكن إلغاء أي تصفير بقيد عكسي. الديون والسلف ما تتغير.';
                }),
                Textarea::make('reason')->label('السبب / الملاحظة')->required()->rows(2)->maxLength(500)->placeholder('مثال: جرد نهاية الشهر'),
                TextInput::make('confirm')->label('للتأكيد اكتب: تصفير')->required()
                    ->rules([fn () => fn (string $attribute, $value, \Closure $fail) => trim((string) $value) === 'تصفير' ? null : $fail('اكتب كلمة «تصفير» كما هي.')]),
            ])
            ->modalSubmitActionLabel('تصفير')
            ->action(function (array $data, Action $action) {
                try {
                    $resets = app(InternalTransfers::class)->resetAllBalances($data['reason'], $data['idempotency_key'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('صُفّر الرصيد الكلي')
                    ->body($resets->map(fn (MoneyAccountReset $r) => $r->moneyAccount->name.': '.number_format($r->amount))->implode(' · '))->send();
            });
    }

    protected function getViewData(): array
    {
        $b = app(ReportService::class)->balances();

        return [
            'showTotal' => (bool) app(Settings::class)->get('dashboard.show_total_balance'),
            'total' => $b['cash'] + $b['electronic'] + $b['company'] + $b['custody'],
            'custody' => $b['custody'],
            'custodyHolders' => $b['custody_holders'],
            'advances' => $b['advances'],
            'advancesCount' => $b['advances_count'],
            'showEmployees' => auth()->user()->can('employees.view'),
            // Each card opens its details, for those allowed to see them.
            'links' => [
                'boxes' => self::url(MoneyAccountResource::class),
                'secondary' => self::url(DebtResource::class, ['tab' => 'secondary']),
                'primary' => self::url(DebtResource::class, ['tab' => 'primary']),
                'custody' => self::url(EmployeeResource::class),
                'advances' => self::url(EmployeeAdvanceResource::class),
            ],
            'cash' => $b['cash'],
            'electronic' => $b['electronic'],
            'company' => $b['company'],
            'secondary' => $b['secondary'],
            'secondaryAccounts' => $b['secondary_accounts'],
            'primary' => $b['primary'],
            'primaryAccounts' => $b['primary_accounts'],
        ];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function url(string $resource, array $parameters = []): ?string
    {
        return $resource::canViewAny() ? $resource::getUrl('index', $parameters) : null;
    }
}

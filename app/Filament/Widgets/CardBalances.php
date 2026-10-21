<?php

namespace App\Filament\Widgets;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CardAccounts\CardAccountResource;
use App\Models\MoneyAccount;
use App\Models\MoneyAccountReset;
use App\Services\InternalTransfers;
use App\Services\TreasuryService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * «رصيد البطاقات»: what is on all the cards together (Qi Card, Super Key, Mastercard…), card by card,
 * with one button that zeroes them all as documented resets and one that takes an amount spent off one card.
 */
class CardBalances extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.card-balances';

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->can('cards.view');
    }

    /** @return array{total: int, cards: Collection<int, array{name: string, balance: int}>} */
    public static function balances(): array
    {
        $treasury = app(TreasuryService::class);
        $cards = MoneyAccount::where('kind', MoneyAccountKind::Electronic)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (MoneyAccount $m) => ['name' => $m->name, 'balance' => $treasury->balance($m)]);

        return ['total' => (int) $cards->sum('balance'), 'cards' => $cards];
    }

    public function resetAllAction(): Action
    {
        return self::makeResetAllAction();
    }

    /** The same button on the card accounts page. */
    public static function makeResetAllAction(): Action
    {
        return Action::make('resetAll')->label('تصفير رصيد البطاقات')->icon('heroicon-o-arrow-path')->color('danger')
            ->visible(fn () => auth()->user()->can('cards.reset'))
            ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
            ->modalHeading('تصفير رصيد كل البطاقات')
            ->schema([
                Hidden::make('idempotency_key'),
                Text::make(fn () => 'الرصيد الكلي الآن: '.Money::format(self::balances()['total'])
                    .'. كل بطاقة عليها رصيد تُصفَّر بتسوية موثقة برقم، وتبقى حركاتها السابقة كما هي، ويمكن إلغاء أي تصفير بقيد عكسي.'),
                Textarea::make('reason')->label('السبب / الملاحظة')->required()->rows(2)->maxLength(500)
                    ->placeholder('مثال: سحب رصيد البطاقات نقداً'),
            ])
            ->requiresConfirmation()
            ->modalSubmitActionLabel('تصفير')
            ->action(function (array $data, Action $action) {
                try {
                    $resets = app(InternalTransfers::class)->resetAllCards($data['reason'], $data['idempotency_key'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('صُفّرت أرصدة البطاقات')
                    ->body($resets->map(fn (MoneyAccountReset $r) => $r->moneyAccount->name.': '.number_format($r->amount))->implode(' · '))->send();
            });
    }

    public function resetPartAction(): Action
    {
        return self::makeResetPartAction();
    }

    /** «تصفير جزء من رصيد البطاقة»: an amount spent from one card comes off its balance. */
    public static function makeResetPartAction(): Action
    {
        return Action::make('resetPart')->label('تصفير جزء من رصيد البطاقة')->icon('heroicon-o-minus-circle')->color('warning')
            ->visible(fn () => auth()->user()->can('cards.reset'))
            ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
            ->modalHeading('تصفير جزء من رصيد البطاقة')
            ->schema([
                Hidden::make('idempotency_key'),
                Select::make('card')->label('البطاقة')->required()->native(false)
                    ->options(fn () => MoneyAccount::where('kind', MoneyAccountKind::Electronic)->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (MoneyAccount $m) => [$m->id => $m->name.' – '.Money::format(app(TreasuryService::class)->balance($m))])),
                TextInput::make('amount')->label('المبلغ المصروف (د.ع)')->integer()->minValue(1)->required(),
                Textarea::make('reason')->label('السبب / على شنو صرفت')->required()->rows(2)->maxLength(500)
                    ->placeholder('مثال: شحن رصيد الشركة من البطاقة'),
                Text::make('ينزل المبلغ من رصيد البطاقة بتسوية موثقة برقم، وتبقى حركاتها السابقة، ويمكن إلغاؤه بقيد عكسي.'),
            ])
            ->modalSubmitActionLabel('تسجيل وتنزيل من الرصيد')
            ->action(function (array $data, Action $action) {
                try {
                    $reset = app(InternalTransfers::class)->resetCardPart(
                        MoneyAccount::findOrFail($data['card']), (int) $data['amount'], $data['reason'], $data['idempotency_key'] ?? null,
                    );
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('نزل '.Money::format((int) $reset->amount).' من '.$reset->moneyAccount->name)
                    ->body('رقم التسوية: '.$reset->number)->send();
            });
    }

    protected function getViewData(): array
    {
        return self::balances() + [
            'detailsUrl' => CardAccountResource::canViewAny() ? CardAccountResource::getUrl() : null,
        ];
    }
}

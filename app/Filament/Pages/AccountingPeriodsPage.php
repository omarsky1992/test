<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Models\PeriodClosing;
use App\Services\AccountingPeriods;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * «الفترات المحاسبية»: purchases and sales for any period you choose (e.g. the 15th to the 14th),
 * closing purchases and sales separately or together, and the reports of past periods.
 */
class AccountingPeriodsPage extends Page
{
    protected string $view = 'filament.pages.accounting-periods';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'الفترات المحاسبية';

    protected static ?string $slug = 'accounting-periods';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Exact moments (ISO) when showing an open or a closed period; empty for whole days from the date inputs. */
    public string $sinceAt = '';

    public string $untilAt = '';

    public static function canAccess(): bool
    {
        return auth()->user()->can('periods.view');
    }

    public function updatedFrom(): void
    {
        $this->sinceAt = $this->untilAt = '';
    }

    public function updatedTo(): void
    {
        $this->sinceAt = $this->untilAt = '';
    }

    public function mount(): void
    {
        $periods = app(AccountingPeriods::class);
        $this->from = $this->from ?: collect([$periods->currentStart('purchases'), $periods->currentStart('sales')])->min()->toDateString();
        $this->to = $this->to ?: CarbonImmutable::today()->toDateString();
    }

    public function showClosing(int $id): void
    {
        $closing = PeriodClosing::findOrFail($id);
        $this->from = $closing->starts_on->toDateString();
        $this->to = $closing->ends_on->toDateString();
        $this->sinceAt = $closing->starts_at->toIso8601String();
        $this->untilAt = $closing->ends_at->toIso8601String();
    }

    public function showCurrent(string $scope): void
    {
        $start = app(AccountingPeriods::class)->currentStart($scope);
        $this->from = $start->setTimezone(config('app.timezone'))->toDateString();
        $this->to = CarbonImmutable::today()->toDateString();
        $this->sinceAt = $start->toIso8601String();
        $this->untilAt = '';
    }

    /**
     * «تصفير» purchases, sales or both = closing their open period: the totals are kept as a closed
     * period, nothing is deleted and no balance moves. Also on the dashboard.
     */
    public static function closeAction(string $name, string $label, array $scopes, string $color): Action
    {
        return Action::make($name)->label($label)->color($color)
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn () => auth()->user()->can('periods.close'))
            ->fillForm(['ends_on' => CarbonImmutable::today()->toDateString()])
            ->modalHeading($label)
            ->schema([
                Text::make(fn () => collect($scopes)->map(fn ($s) => PeriodClosing::SCOPES[$s].' منذ '.app(AccountingPeriods::class)->currentStart($s)->setTimezone(config('app.timezone'))->format('Y/m/d H:i').': '
                    .number_format(app(AccountingPeriods::class)->totals(app(AccountingPeriods::class)->currentStart($s), CarbonImmutable::now(), exact: true)[$s]).' د.ع')->implode(' · ')
                    .'. التصفير يحفظ هذه المجاميع في «الفترات المغلقة» ويبدأ العدّ من الصفر الآن. لا يحذف أي عملية ولا يغيّر أي رصيد.'),
                DatePicker::make('ends_on')->label('حتى (اليوم = الآن)')->required()->maxDate(now()),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (array $data, Action $action) use ($scopes) {
                try {
                    $closed = app(AccountingPeriods::class)->close($scopes, CarbonImmutable::parse($data['ends_on']), $data['notes'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('تم التصفير')->body($closed->map(fn ($c) => PeriodClosing::SCOPES[$c->scope].': '.number_format($c->total))->implode(' · ').' — بدأت فترة جديدة من اليوم التالي.')->send();
            });
    }

    /** @return array<int, Action> */
    public static function closeActions(): array
    {
        return [
            self::closeAction('closePurchases', 'تصفير المشتريات', ['purchases'], 'warning'),
            self::closeAction('closeSales', 'تصفير المبيعات', ['sales'], 'info'),
            self::closeAction('closeBoth', 'تصفير المبيعات والمشتريات معاً', ['purchases', 'sales'], 'primary'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...self::closeActions(),
            Action::make('openNew')->label('فتح فترة جديدة')->icon(Heroicon::OutlinedCalendar)->color('gray')
                ->visible(fn () => auth()->user()->can('periods.close'))
                ->modalDescription('تبدأ الفترة المفتوحة من اليوم الذي تختاره (مثلاً يوم 15). لا تغيّر أي عملية أو رصيد.')
                ->schema([
                    ToggleButtons::make('scopes')->label('لـ')->multiple()->inline()->required()
                        ->options(PeriodClosing::SCOPES)->default(['purchases', 'sales']),
                    DatePicker::make('starts_on')->label('تبدأ من')->required(),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        foreach ($data['scopes'] as $scope) {
                            app(AccountingPeriods::class)->openNew($scope, CarbonImmutable::parse($data['starts_on']));
                        }
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    $this->showCurrent($data['scopes'][0]);
                    Notification::make()->success()->title('فُتحت فترة جديدة')->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $periods = app(AccountingPeriods::class);
        $exact = filled($this->sinceAt);
        if ($exact) {
            $from = CarbonImmutable::parse($this->sinceAt);
            $to = filled($this->untilAt) ? CarbonImmutable::parse($this->untilAt) : CarbonImmutable::now();
        } else {
            $from = filled($this->from) ? CarbonImmutable::parse($this->from) : CarbonImmutable::today();
            $to = filled($this->to) ? CarbonImmutable::parse($this->to) : CarbonImmutable::today();
            if ($to->lessThan($from)) {
                [$from, $to] = [$to, $from];
            }
        }

        return [
            'rangeFrom' => $from,
            'rangeTo' => $to,
            'exact' => $exact,
            'totals' => $periods->totals($from, $to, $exact),
            'operations' => $periods->operations($from, $to, exact: $exact),
            'current' => [
                'purchases' => $periods->currentStart('purchases'),
                'sales' => $periods->currentStart('sales'),
            ],
            'closings' => PeriodClosing::with('closer')->latest('ends_at')->latest('id')->limit(48)->get(),
        ];
    }
}

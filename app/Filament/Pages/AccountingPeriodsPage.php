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

    public static function canAccess(): bool
    {
        return auth()->user()->can('periods.view');
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
    }

    public function showCurrent(string $scope): void
    {
        $this->from = app(AccountingPeriods::class)->currentStart($scope)->toDateString();
        $this->to = CarbonImmutable::today()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        $close = fn (string $name, string $label, array $scopes, string $color) => Action::make($name)->label($label)->color($color)
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn () => auth()->user()->can('periods.close'))
            ->fillForm(['ends_on' => CarbonImmutable::today()->toDateString()])
            ->modalHeading($label)
            ->schema([
                Text::make(fn () => collect($scopes)->map(fn ($s) => PeriodClosing::SCOPES[$s].' تبدأ من '.app(AccountingPeriods::class)->currentStart($s)->format('Y/m/d'))->implode(' · ')
                    .'. الإغلاق يحفظ المجاميع النهائية ولا يحذف أي عملية ولا يغيّر أرصدة الموظفين أو البطاقات.'),
                DatePicker::make('ends_on')->label('نهاية الفترة (تشمل هذا اليوم)')->required()->maxDate(now()),
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
                Notification::make()->success()->title('أُغلقت الفترة')->body($closed->map(fn ($c) => PeriodClosing::SCOPES[$c->scope].': '.number_format($c->total))->implode(' · ').' — بدأت فترة جديدة من اليوم التالي.')->send();
            });

        return [
            $close('closePurchases', 'إغلاق فترة المشتريات', ['purchases'], 'warning'),
            $close('closeSales', 'إغلاق فترة المبيعات', ['sales'], 'info'),
            $close('closeBoth', 'إغلاق المشتريات والمبيعات معاً', ['purchases', 'sales'], 'primary'),
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
        $from = filled($this->from) ? CarbonImmutable::parse($this->from) : CarbonImmutable::today();
        $to = filled($this->to) ? CarbonImmutable::parse($this->to) : CarbonImmutable::today();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'rangeFrom' => $from,
            'rangeTo' => $to,
            'totals' => $periods->totals($from, $to),
            'operations' => $periods->operations($from, $to),
            'current' => [
                'purchases' => $periods->currentStart('purchases'),
                'sales' => $periods->currentStart('sales'),
            ],
            'closings' => PeriodClosing::with('closer')->latest('ends_on')->latest('id')->limit(48)->get(),
        ];
    }
}

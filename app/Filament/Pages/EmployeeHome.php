<?php

namespace App\Filament\Pages;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Actions\Operations;
use App\Filament\Resources\SubscriberCards\SubscriberCardResource;
use App\Models\ActivationDue;
use App\Models\CustodyHandoverRequest;
use App\Models\Debt;
use App\Models\EmployeeAdvance;
use App\Models\Payment;
use App\Services\ActivationDueService;
use App\Services\EmployeeFinance;
use App\Support\SubscriberStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The employee interface's home: the employee's own money, the subscriber groups, the debts and
 * «يجب التفعيل». The admin sees it after switching to the employee interface.
 */
class EmployeeHome extends Page
{
    protected string $view = 'filament.pages.employee-home';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    protected static ?string $title = 'الرئيسية';

    protected static ?string $slug = 'home';

    public static function canAccess(): bool
    {
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->usesEmployeeUi() ?? false;
    }

    public function getHeading(): string
    {
        return 'أهلاً، '.auth()->user()->name;
    }

    public function getSubheading(): ?string
    {
        return CarbonImmutable::now()->translatedFormat('l j F Y');
    }

    public function mount(): void
    {
        if (request()->query('action') === 'pay' && auth()->user()->can('payments.create')) {
            $this->mountAction('pay');
        }
    }

    public function payAction(): Action
    {
        return Operations::pay('pay');
    }

    public function activateAction(): Action
    {
        return Operations::activate('activate');
    }

    public function markDone(int $accountId): void
    {
        abort_unless(auth()->user()->can('activations.mark_done'), 403);
        try {
            $dues = ActivationDue::where('account_id', $accountId)->where('status', 'pending')->get();
            foreach ($dues as $due) {
                app(ActivationDueService::class)->markDone($due);
            }
            Notification::make()->success()->title('تم التفعيل')->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $finance = app(EmployeeFinance::class);
        $today = [CarbonImmutable::today()->startOfDay(), CarbonImmutable::today()->endOfDay()];
        $mine = Payment::where('created_by', $user->id)->where('status', DocumentStatus::Posted)->whereBetween('received_at', $today);
        $canSee = $user->can('accounts.view') || $user->can('subscribers.view');
        $canDebts = $user->can('debts.view');
        $open = fn (DebtBucket $b) => Debt::where('bucket', $b)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);

        return [
            'me' => [
                'custody' => $finance->custodyBalance($user),
                'collected' => (int) (clone $mine)->sum('amount'),
                'receipts' => (clone $mine)->count(),
                'advances' => (int) EmployeeAdvance::where('user_id', $user->id)->sum('balance'),
                'advances_count' => EmployeeAdvance::where('user_id', $user->id)->where('balance', '>', 0)->count(),
                'pending_handover' => CustodyHandoverRequest::where('user_id', $user->id)->where('status', 'pending')->first(),
            ],
            'canSee' => $canSee,
            'counts' => $canSee ? SubscriberStatus::counts() : [],
            'canDebts' => $canDebts,
            'debts' => $canDebts ? [
                'secondary' => (int) $open(DebtBucket::Secondary)->sum('balance'),
                'secondary_accounts' => $open(DebtBucket::Secondary)->distinct()->count('account_id'),
                'primary' => (int) $open(DebtBucket::Primary)->sum('balance'),
            ] : [],
            // One row per subscription, even when it paid more than one secondary debt.
            'dues' => $canSee ? ActivationDue::with(['subscriber:id,full_name', 'account:id,username'])->where('status', 'pending')->orderBy('paid_at')->limit(50)->get()
                ->groupBy('account_id')->map(fn ($g) => tap($g->first(), fn ($d) => $d->amount = (int) $g->sum('amount')))->take(10)->values() : collect(),
            'canMarkDone' => $user->can('activations.mark_done'),
            'handoverRequests' => $user->can('custody.settle') ? CustodyHandoverRequest::where('status', 'pending')->count() : 0,
            'cardsUrl' => fn (string $tab) => SubscriberCardResource::getUrl('index', ['tab' => $tab]),
        ];
    }
}

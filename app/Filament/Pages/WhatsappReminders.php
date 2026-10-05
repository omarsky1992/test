<?php

namespace App\Filament\Pages;

use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\MessageTemplate;
use App\Services\Audit;
use App\Support\Arabic;
use App\Support\Money;
use App\Support\SubscriberStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Reminders to subscribers from the admin's fixed messages: the employee picks subscribers and one
 * message, and each one opens in WhatsApp ready to send from the employee's own phone.
 */
class WhatsappReminders extends Page
{
    protected string $view = 'filament.pages.whatsapp-reminders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'تذكير واتساب';

    protected static ?string $slug = 'reminders';

    private const LIMIT = 60;

    #[Url]
    public string $filter = 'expiring';

    #[Url]
    public string $search = '';

    #[Url(as: 'account')]
    public ?int $preselect = null;

    /** @var array<int, int|string> */
    public array $selected = [];

    public ?int $template = null;

    public static function canAccess(): bool
    {
        return auth()->user()->can('whatsapp.remind');
    }

    public function mount(): void
    {
        if ($this->preselect) {
            $this->filter = 'all';
            $this->selected = [(string) $this->preselect];
        }
        $this->template = MessageTemplate::where('is_active', true)->orderBy('sort_order')->value('id');
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
    }

    public function selectAll(): void
    {
        $this->selected = $this->accounts()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function opened(int $accountId): void
    {
        $account = Account::find($accountId);
        $template = MessageTemplate::find($this->template);
        if ($account && $template) {
            app(Audit::class)->log('whatsapp.reminder', $account, null, ['template' => $template->title], null, $account->subscriber_id);
        }
    }

    /**
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        $query = SubscriberStatus::apply(SubscriberStatus::base(), $this->filter)
            ->with(['subscriber', 'currentPlan'])
            ->withSum(['debts as open_due' => fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])], 'balance');
        if (filled($this->search)) {
            $query->whereIn('accounts.subscriber_id', \App\Models\Subscriber::query()->search($this->search)->select('subscribers.id'));
        }
        $accounts = $query->orderByRaw(SubscriberStatus::ENDS.' asc nulls last')->limit(self::LIMIT)->get();

        if ($this->preselect && ! $accounts->contains('id', $this->preselect) && ($extra = Account::with(['subscriber', 'currentPlan'])
            ->withSum(['debts as open_due' => fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])], 'balance')->find($this->preselect))) {
            $accounts->prepend($extra);
        }

        return $accounts;
    }

    public static function values(Account $account): array
    {
        $ends = SubscriberStatus::endsAt($account);

        return [
            '{الاسم}' => (string) $account->subscriber?->full_name,
            '{الفئة}' => (string) ($account->currentPlan?->name_ar ?? $account->external_plan ?? ''),
            '{الأيام}' => (string) (SubscriberStatus::daysLeft($account) ?? 0),
            '{المبلغ}' => Money::format((int) ($account->open_due ?? 0)),
            '{تاريخ_الانتهاء}' => $ends ? $ends->format('Y/m/d') : '—',
            '{اليوزر}' => (string) $account->username,
        ];
    }

    public static function phone(Account $account): ?string
    {
        $phone = Arabic::phone($account->phone ?? $account->subscriber?->phone);

        return strlen($phone) >= 10 ? preg_replace('/^00/', '', $phone) : null;
    }

    protected function getViewData(): array
    {
        $accounts = $this->accounts();
        $template = MessageTemplate::find($this->template);
        $chosen = $accounts->whereIn('id', array_map('intval', $this->selected))->values();

        return [
            'accounts' => $accounts,
            'limit' => self::LIMIT,
            'templates' => MessageTemplate::where('is_active', true)->orderBy('sort_order')->get(),
            'chosenTemplate' => $template,
            'messages' => $template ? $chosen->map(fn (Account $a) => [
                'account' => $a,
                'phone' => self::phone($a),
                'text' => $text = $template->render(self::values($a)),
                'url' => self::phone($a) ? 'https://wa.me/'.self::phone($a).'?text='.rawurlencode($text) : null,
            ]) : collect(),
            'filters' => array_diff_key(SubscriberStatus::FILTERS, ['active' => 1]),
        ];
    }
}

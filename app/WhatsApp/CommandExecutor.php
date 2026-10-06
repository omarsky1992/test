<?php

namespace App\WhatsApp;

use App\Enums\AccountStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Activation;
use App\Models\ActivationDue;
use App\Models\Debt;
use App\Models\DeviceType;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ActivationDueService;
use App\Services\Audit;
use App\Services\DebtService;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\RenewalService;
use App\Services\ReportService;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\Support\Arabic;
use App\Support\Money;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Carries out a command as the user the number is linked to, through the same services as the
 * panel and with that user's permissions. Returns a short reply and what changed.
 */
class CommandExecutor
{
    public function __construct(
        private RenewalService $renewals,
        private PaymentService $payments,
        private DebtService $debts,
        private TreasuryService $treasury,
        private EmployeeFinance $employees,
        private ReportService $reports,
        private Settings $settings,
        private Audit $audit,
    ) {}

    public function execute(Command $command, User $user, string $messageId): Result
    {
        try {
            return match ($command->intent) {
                'activate' => $this->activate($command, $user),
                'payment' => $this->payment($command, $user, $messageId),
                'purchase' => $this->purchase($command, $user),
                'add_debt' => $this->addDebt($command, $user),
                'transfer' => $this->transfer($command, $user),
                'sale' => $this->sale($command, $user),
                'void_debt' => $this->voidDebt($command, $user),
                'query' => $this->query($command, $user),
                'clarify' => Result::clarify($command->question ?? 'ما فهمت الطلب تماماً، وضّحه أكثر.'),
                default => Result::clarify($command->question ?? self::help()),
            };
        } catch (BusinessRuleException $e) {
            return Result::failed('❌ '.$e->getMessage());
        }
    }

    public static function help(): string
    {
        return "ما فهمت الطلب. أمثلة:\n• محمد رمضان فعلته سبع أيام\n• علي حسين دفع 25 الف\n• سجل دين على محمد 20 الف\n• ناقل دين محمد\n• بعت راوتر ب 40 الف\n• شريت كيبل 30 متر سعر المتر 5 آلاف\n• امسح دين محمد\n• الديون الثانوية / المتأخرين / مبيعات اليوم / عهد الموظفين";
    }

    private function activate(Command $c, User $user): Result
    {
        if (! $user->can('activations.create')) {
            return Result::denied();
        }
        if ($c->subscriber === null) {
            return Result::clarify('منو المشترك اللي فعلته؟');
        }
        if ($c->days === null || $c->days <= 0) {
            return Result::clarify("كم يوم فعلت {$c->subscriber}؟");
        }
        $account = $this->findAccount($c->subscriber);
        if (is_string($account)) {
            return Result::clarify($account);
        }
        $name = $account->subscriber->full_name;

        [$renewal, $duplicate, $newEnds] = $this->recordActivation($account, $c->days);
        if ($duplicate) {
            return Result::done("ℹ️ تفعيل {$name} مسجل مسبقاً ({$renewal->detected_at->diffForHumans()}) – ما سجلته مرة ثانية.", ['renewal' => $renewal->reference, 'duplicate' => true]);
        }

        $debt = $renewal->debt;
        $reply = match ($renewal->status) {
            'debt_created' => "✅ تم تفعيل {$name} {$c->days} أيام\nدين ثانوي: ".Money::format($debt->original_amount)." ({$debt->number})",
            'activated' => "✅ تم تسجيل تفعيل {$name} {$c->days} يوم (تفعيل كامل بدون دين)",
            default => "✅ تم تسجيل تفعيل {$name} {$c->days} أيام\n⚠️ الفئة بلا سعر، ما انسجل دين",
        };

        return Result::done($reply, [
            'account_id' => $account->id, 'subscriber' => $name, 'renewal' => $renewal->reference, 'status' => $renewal->status,
            'debt' => $debt?->number, 'amount' => $debt?->original_amount, 'ends_at' => $newEnds->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Records an activation of $days on the company site, the same way a sync sees it, and moves the
     * company end date so the next sync does not count it again. The same account is not activated
     * twice within the duplicate window: then the earlier renewal comes back with $duplicate true.
     *
     * @return array{0: AccountRenewal, 1: bool, 2: CarbonImmutable}
     */
    private function recordActivation(Account $account, int $days): array
    {
        $hours = (int) $this->settings->get('whatsapp.duplicate_hours');
        if ($recent = AccountRenewal::where('account_id', $account->id)->where('detected_at', '>=', now()->subHours($hours))->latest('detected_at')->first()) {
            return [$recent, true, $recent->new_ends_at ?? CarbonImmutable::now()];
        }

        $now = CarbonImmutable::now();
        $previousEnds = $account->external_ends_at;
        $left = Account::daysLeft($previousEnds, $now);
        $previousDays = min($account->company_days_left ?? $left ?? 0, $left ?? $account->company_days_left ?? 0);
        $base = $previousEnds !== null && $previousEnds->greaterThan($now) ? $previousEnds : $now;
        $newEnds = $base->addDays($days);

        $renewal = DB::transaction(function () use ($account, $previousDays, $days, $previousEnds, $newEnds, $now) {
            $renewal = $this->renewals->record($account, $previousDays, $days, $previousEnds, $newEnds, at: $now);
            $original = $account->getAttributes();
            // The next company sync sees these days as already known, so it never counts the renewal twice.
            $account->update(['external_ends_at' => $newEnds, 'company_days_left' => Account::daysLeft($newEnds, $now)]);
            $this->audit->changes('account.activated', $account, $original, 'تفعيل عبر واتساب', $account->subscriber_id);
            app(ActivationDueService::class)->accountEndsChanged($account);

            return $renewal;
        });

        return [$renewal, false, $newEnds];
    }

    private function payment(Command $c, User $user, string $messageId): Result
    {
        if (! $user->can('payments.create')) {
            return Result::denied();
        }
        if ($c->subscriber === null) {
            return Result::clarify('منو المشترك اللي دفع؟');
        }
        if ($c->amount === null || $c->amount <= 0) {
            return Result::clarify("شكد دفع {$c->subscriber}؟");
        }
        $account = $this->findAccount($c->subscriber);
        if (is_string($account)) {
            return Result::clarify($account);
        }
        $method = $this->payments->defaultMethod(PaymentMethod::Cash);
        $box = $user->collects_to_custody
            ? $this->employees->custodyAccount($user)
            : ($method->moneyAccount ?? $this->cashBox($user));

        $payment = $this->payments->record($account, $c->amount, PaymentMethod::Cash, $box, notes: 'عبر واتساب', idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, "whatsapp:{$messageId}")->toString());
        $left = (int) Debt::where('account_id', $account->id)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->sum('balance');

        return Result::done(
            '✅ قبض '.Money::format($c->amount)." من {$account->subscriber->full_name}\nسند {$payment->receipt_number}\nالمتبقي عليه: ".Money::format($left),
            ['payment' => $payment->receipt_number, 'amount' => $c->amount, 'account_id' => $account->id, 'box' => $box->name, 'remaining' => $left],
        );
    }

    private function purchase(Command $c, User $user): Result
    {
        if (! $user->can('expenses.create')) {
            return Result::denied();
        }
        $items = array_values(array_filter($c->items, fn ($i) => $i['description'] !== ''));
        if ($items === []) {
            return Result::clarify('شنو اللي شريته وبكم؟');
        }
        foreach ($items as &$item) {
            $item['total'] ??= $item['quantity'] !== null && $item['unit_price'] !== null ? $item['quantity'] * $item['unit_price'] : null;
            if ($item['total'] === null || $item['total'] <= 0) {
                return Result::clarify("شكد سعر {$item['description']}؟");
            }
        }
        unset($item);

        $box = $user->collects_to_custody ? $this->employees->custodyAccount($user) : $this->cashBox($user);
        $allowNegative = $user->can('cash.allow_negative');
        $expenses = DB::transaction(fn () => array_map(function (array $item) use ($box, $allowNegative) {
            $description = $item['description'].($item['quantity'] && $item['unit_price'] ? ' × '.number_format($item['unit_price']) : '');

            return $this->treasury->recordExpense(self::category($item['description']), mb_substr($description, 0, 200), $item['total'], $box, notes: 'عبر واتساب', allowNegative: $allowNegative);
        }, $items));

        $total = array_sum(array_map(fn (Expense $e) => $e->amount, $expenses));
        $lines = array_map(fn (Expense $e) => "• {$e->description}: ".Money::format($e->amount, false), $expenses);

        return Result::done("✅ تم تسجيل المشتريات من {$box->name}\n".implode("\n", $lines)."\nالمجموع: ".Money::format($total), [
            'expenses' => array_map(fn (Expense $e) => ['number' => $e->number, 'description' => $e->description, 'amount' => $e->amount], $expenses),
            'total' => $total, 'box' => $box->name,
        ]);
    }

    /**
     * A debt on a subscriber. A primary debt is a 30-day activation on credit: when the subscription is
     * not running (or the message says «تفعيل»), it is activated too, so the subscriber shows as
     * «فعّال وعليه دين», not «منتهي وعليه دين». Without an amount, the plan price is owed.
     */
    private function addDebt(Command $c, User $user): Result
    {
        if (! $user->can('debts.create_manual')) {
            return Result::denied();
        }
        if ($c->subscriber === null) {
            return Result::clarify('على منو أسجل الدين؟');
        }
        if ($c->amount !== null && $c->amount <= 0) {
            return Result::clarify("شكد الدين على {$c->subscriber}؟");
        }
        $account = $this->findAccount($c->subscriber);
        if (is_string($account)) {
            return Result::clarify($account);
        }
        $name = $account->subscriber->full_name;
        $bucket = $c->bucket === 'secondary' ? DebtBucket::Secondary : DebtBucket::Primary;
        $amount = $c->amount ?? ($bucket === DebtBucket::Primary && $account->currentPlan?->price > 0 ? (int) $account->currentPlan->price : null);
        if ($amount === null) {
            return Result::clarify("شكد الدين على {$name}؟");
        }

        $activation = null;
        if ($bucket === DebtBucket::Primary) {
            if ($c->days !== null && $c->days <= $this->settings->partialDays()) {
                return Result::clarify("تفعيل {$c->days} أيام يُسجَّل ديناً ثانوياً تلقائياً. أرسل: تفعيل {$name} {$c->days}");
            }
            $ends = SubscriberStatus::endsAt($account);
            if ($c->days !== null || $ends === null || $ends->getTimestamp() <= now()->getTimestamp()) {
                if (! $user->can('activations.create')) {
                    return Result::denied();
                }
                $activation = $this->recordActivation($account, $c->days ?? $this->settings->fullDays());
            }
        }

        $debt = $this->debts->create($account, $amount, $bucket, DebtSource::Manual, notes: $activation ? 'تفعيل بدين أولي عبر واتساب' : 'عبر واتساب');
        $total = (int) Debt::where('account_id', $account->id)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->sum('balance');
        $label = $bucket === DebtBucket::Secondary ? 'ثانوي' : 'أولي';
        $reply = "✅ تم تسجيل دين {$label} ".Money::format($amount)." على {$name} ({$debt->number})";
        if ($activation !== null) {
            [$renewal, $duplicate, $newEnds] = $activation;
            $reply .= $duplicate
                ? "\nℹ️ تفعيله مسجل مسبقاً ({$renewal->detected_at->diffForHumans()})"
                : "\n✅ وتم تفعيله {$renewal->new_days} يوم (فعّال حتى ".$newEnds->setTimezone(config('app.timezone'))->format('Y/m/d').')';
        }

        return Result::done($reply."\nمجموع ديونه: ".Money::format($total), [
            'debt' => $debt->number, 'amount' => $amount, 'bucket' => $bucket->value, 'account_id' => $account->id,
            'activated' => $activation !== null && ! $activation[1], 'renewal' => $activation[0]->reference ?? null,
        ]);
    }

    /** مناقلة: the oldest open secondary debt moves to the primary debts. */
    private function transfer(Command $c, User $user): Result
    {
        if (! $user->can('transfers.create')) {
            return Result::denied();
        }
        if ($c->subscriber === null) {
            return Result::clarify('دين منو أناقل؟');
        }
        $account = $this->findAccount($c->subscriber);
        if (is_string($account)) {
            return Result::clarify($account);
        }
        $name = $account->subscriber->full_name;
        $debt = Debt::where('account_id', $account->id)->where('bucket', DebtBucket::Secondary)
            ->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->orderBy('debt_date')->orderBy('id')->first();
        if (! $debt) {
            return Result::failed("{$name} ما عليه دين ثانوي للمناقلة.");
        }
        $transfer = $this->debts->transfer($debt, 'مناقلة عبر واتساب');

        return Result::done("🔁 تمت المناقلة {$transfer->number}: ".Money::format($transfer->amount)." من الثانوية إلى الأولية على {$name}"
            .($transfer->days_added ? "\nأُضيفت {$transfer->days_added} يوماً، نفّذها على موقع الشركة." : ''), [
                'transfer' => $transfer->number, 'amount' => $transfer->amount, 'account_id' => $account->id,
            ]);
    }

    /** مبيعات: devices or items sold, paid now into the seller's box, or on credit to a named subscriber. */
    private function sale(Command $c, User $user): Result
    {
        if (! $user->can('sales.create')) {
            return Result::denied();
        }
        $items = array_values(array_filter($c->items, fn ($i) => $i['description'] !== ''));
        if ($items === []) {
            return Result::clarify('شنو اللي بعته وبكم؟');
        }
        $account = null;
        if ($c->onCredit) {
            if ($c->subscriber === null) {
                return Result::clarify('على منو البيع بالدين؟');
            }
            $account = $this->findAccount($c->subscriber);
            if (is_string($account)) {
                return Result::clarify($account);
            }
        }
        $box = $account ? null : ($user->collects_to_custody ? $this->employees->custodyAccount($user) : $this->cashBox($user));

        $lines = [];
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit = $item['unit_price'] ?? ($item['total'] !== null && $item['total'] % $quantity === 0 ? intdiv($item['total'], $quantity) : null);
            if ($unit === null && $item['total'] !== null) {
                [$unit, $quantity] = [$item['total'], 1];
            }
            if ($unit === null || $unit <= 0) {
                return Result::clarify("بكم بعت {$item['description']}؟");
            }
            $lines[] = [$item['description'], $unit, $quantity];
        }

        $sales = DB::transaction(fn () => array_map(fn (array $l) => $this->treasury->recordDeviceSale(
            self::deviceType($l[0]), mb_substr($l[0], 0, 200), $l[1], $l[2], null, $box, $account, $account?->subscriber?->full_name, null, 'عبر واتساب',
        ), $lines));

        $total = array_sum(array_map(fn ($s) => $s->total_amount, $sales));
        $list = implode("\n", array_map(fn ($s) => "• {$s->description}: ".Money::format($s->total_amount, false), $sales));

        return Result::done('✅ تم تسجيل المبيعات'.($account ? " بالدين على {$account->subscriber->full_name}" : " في {$box->name}")."\n{$list}\nالمجموع: ".Money::format($total), [
            'sales' => array_map(fn ($s) => ['number' => $s->number, 'amount' => $s->total_amount], $sales), 'total' => $total,
            'account_id' => $account?->id,
        ]);
    }

    private static function deviceType(string $description): DeviceType
    {
        $d = Arabic::normalize($description);
        $key = match (true) {
            (bool) preg_match('/راوتر|router/u', $d) => 'router',
            (bool) preg_match('/ont|اونتي|جهاز/u', $d) => 'ont',
            (bool) preg_match('/مقوي|ريبيتر|اكسس/u', $d) => 'repeater',
            (bool) preg_match('/كيبل|كابل|سلك/u', $d) => 'cable',
            default => 'other',
        };

        return DeviceType::where('key', $key)->first() ?? DeviceType::firstOrCreate(['key' => 'other'], ['name_ar' => 'أخرى']);
    }

    private function voidDebt(Command $c, User $user): Result
    {
        if (! $user->can('debts.void')) {
            return Result::denied();
        }
        if ($c->subscriber === null) {
            return Result::clarify('دين منو تريد تمسح؟');
        }
        $account = $this->findAccount($c->subscriber);
        if (is_string($account)) {
            return Result::clarify($account);
        }
        $name = $account->subscriber->full_name;
        $open = Debt::where('account_id', $account->id)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])
            ->when($c->amount, fn ($q) => $q->where(fn ($q) => $q->where('balance', $c->amount)->orWhere('original_amount', $c->amount)))
            ->orderBy('debt_date')->get();
        if ($open->isEmpty()) {
            return Result::failed("ما عليه ديون مفتوحة {$name}".($c->amount ? ' بهذا المبلغ' : '').'.');
        }
        if ($open->count() > 1) {
            $list = $open->take(5)->map(fn (Debt $d) => "• {$d->number}: ".Money::format($d->balance, false).' ('.$d->debt_date->format('m/d').')')->implode("\n");

            return Result::clarify("{$name} عليه {$open->count()} ديون:\n{$list}\nأي واحد أمسح؟ اكتب المبلغ.");
        }
        $debt = $this->debts->void($open->first(), 'حذف عبر واتساب');

        return Result::done("🗑️ تم مسح دين {$name}: ".Money::format($debt->original_amount)." ({$debt->number})", [
            'debt' => $debt->number, 'amount' => $debt->original_amount, 'account_id' => $account->id,
        ]);
    }

    private function query(Command $c, User $user): Result
    {
        $permission = match ($c->query) {
            'custody', 'advances' => 'employees.view',
            'sales_today', 'purchases_today', 'activated_today' => 'reports.view',
            'must_activate' => 'accounts.view',
            default => 'debts.view',
        };
        if (! $user->can($permission)) {
            return Result::denied();
        }
        $today = CarbonImmutable::today();

        $reply = match ($c->query) {
            'secondary_debts', 'primary_debts' => $this->debtList($c->query === 'secondary_debts' ? DebtBucket::Secondary : DebtBucket::Primary),
            'late' => $this->late(),
            'must_activate' => (function () {
                $dues = ActivationDue::with('subscriber:id,full_name')->where('status', 'pending')->orderBy('paid_at')->limit(15)->get();

                return $dues->isEmpty() ? '✅ ما كو أحد يجب تفعيله.' : "🟣 يجب التفعيل ({$dues->count()}):\n".$dues->map(fn ($d) => "• {$d->subscriber?->full_name}: سدّد ".Money::format($d->amount, false))->implode("\n");
            })(),
            'activated_today' => $this->activatedToday($today),
            'sales_today' => (function () use ($today) {
                $d = $this->reports->dashboard($today, $today);

                return '💰 مبيعات اليوم: '.Money::format($d['sales'])."\nتفعيلات: {$d['activations_count']} · أجهزة: ".Money::format($d['device_sales'], false)."\nالتحصيل: ".Money::format($d['collections'])." ({$d['receipts_count']} سند)";
            })(),
            'purchases_today' => (function () use ($today) {
                $d = $this->reports->dashboard($today, $today);
                $items = Expense::where('status', DocumentStatus::Posted)->whereBetween('spent_at', [$today->startOfDay(), $today->endOfDay()])->latest('spent_at')->limit(8)->get()
                    ->map(fn (Expense $e) => "• {$e->description}: ".Money::format($e->amount, false))->implode("\n");

                return '🛒 مشتريات اليوم: '.Money::format($d['purchases'])."\nمصاريف: ".Money::format($d['expenses'], false).' · شحن رصيد الشركة: '.Money::format($d['company_topups'], false).($items ? "\n{$items}" : '');
            })(),
            'custody' => (function () {
                $rows = MoneyAccount::with('user:id,name')->where('kind', MoneyAccountKind::Custody)->get()
                    ->map(fn (MoneyAccount $m) => [$m->user?->name ?? $m->name, $this->treasury->balance($m)])->filter(fn ($r) => $r[1] !== 0);

                return $rows->isEmpty() ? 'ما كو عهد عند الموظفين.' : '👥 عهد الموظفين: '.Money::format($rows->sum(fn ($r) => $r[1]))."\n".$rows->map(fn ($r) => "• {$r[0]}: ".Money::format($r[1], false))->implode("\n");
            })(),
            'advances' => (function () {
                $rows = EmployeeAdvance::with('user:id,name')->where('balance', '>', 0)->get()->groupBy('user_id')
                    ->map(fn ($g) => [$g->first()->user?->name, (int) $g->sum('balance')]);

                return $rows->isEmpty() ? 'ما كو سلف غير مسددة.' : '💵 سلف الموظفين: '.Money::format($rows->sum(fn ($r) => $r[1]))."\n".$rows->map(fn ($r) => "• {$r[0]}: ".Money::format($r[1], false))->implode("\n");
            })(),
            'subscriber_debt' => (function () use ($c) {
                if ($c->subscriber === null) {
                    return 'دين منو تريد تعرف؟';
                }
                $account = $this->findAccount($c->subscriber);
                if (is_string($account)) {
                    return $account;
                }
                $open = Debt::where('account_id', $account->id)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->get();
                $name = $account->subscriber->full_name;

                return $open->isEmpty() ? "✅ {$name} ما عليه دين." : "{$name} عليه ".Money::format((int) $open->sum('balance'))."\n".$open->map(fn (Debt $d) => "• {$d->bucket->getLabel()}: ".Money::format($d->balance, false))->implode("\n");
            })(),
            default => self::help(),
        };

        return Result::done($reply, ['query' => $c->query]);
    }

    private function debtList(DebtBucket $bucket): string
    {
        $open = Debt::with('subscriber:id,full_name')->where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);
        $total = (int) (clone $open)->sum('balance');
        $rows = (clone $open)->select('subscriber_id', DB::raw('sum(balance) as total'))->groupBy('subscriber_id')->orderByDesc('total')->limit(10)->get();
        $label = $bucket === DebtBucket::Secondary ? 'الديون الثانوية' : 'الديون الأولية';
        if ($rows->isEmpty()) {
            return "✅ ما كو {$label}.";
        }
        $count = (clone $open)->distinct()->count('subscriber_id');
        $names = Subscriber::whereIn('id', $rows->pluck('subscriber_id'))->pluck('full_name', 'id');

        return "📋 {$label}: ".Money::format($total)." ({$count} مشترك)\n".$rows->map(fn ($r) => "• {$names[$r->subscriber_id]}: ".Money::format((int) $r->total, false))->implode("\n").($count > 10 ? "\n…" : '');
    }

    /** Secondary debts still unpaid after the short activation period ended. */
    private function late(): string
    {
        $cutoff = now()->subDays($this->settings->partialDays());
        $rows = Debt::with('subscriber:id,full_name')->where('bucket', DebtBucket::Secondary)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])
            ->where('debt_date', '<', $cutoff)->orderBy('debt_date')->limit(15)->get();
        if ($rows->isEmpty()) {
            return '✅ ما كو متأخرين.';
        }

        return "⏰ المتأخرين ({$rows->count()}):\n".$rows->map(fn (Debt $d) => "• {$d->subscriber?->full_name}: ".Money::format($d->balance, false).' – من '.(int) $d->debt_date->diffInDays(now(), true).' يوم')->implode("\n");
    }

    private function activatedToday(CarbonImmutable $today): string
    {
        $range = [$today->startOfDay(), $today->endOfDay()];
        $renewals = AccountRenewal::with('subscriber:id,full_name')->whereBetween('detected_at', $range)->latest('detected_at')->get();
        $activations = Activation::with('subscriber:id,full_name')->where('status', DocumentStatus::Posted)->whereBetween('created_at', $range)->get();
        $names = $renewals->map(fn ($r) => "• {$r->subscriber?->full_name}: {$r->new_days} يوم")
            ->merge($activations->map(fn ($a) => "• {$a->subscriber?->full_name}: {$a->kind->getLabel()}"));
        if ($names->isEmpty()) {
            return 'ما كو تفعيلات اليوم.';
        }

        return "⚡ المفعلين اليوم ({$names->count()}):\n".$names->take(20)->implode("\n");
    }

    /**
     * The one active account a name, phone or username points to, or a question when there is
     * none or more than one.
     */
    private function findAccount(string $term): Account|string
    {
        $found = $this->lookup($term);
        // Spoken «فعلت لمحمد»: the «ل» (for) sticks to the name.
        if (is_string($found) && str_starts_with($found, 'ما لقيت') && mb_substr($term, 0, 1) === 'ل' && mb_strlen($term) > 3) {
            $again = $this->lookup(mb_substr($term, 1));

            return is_string($again) && str_starts_with($again, 'ما لقيت') ? $found : $again;
        }

        return $found;
    }

    private function lookup(string $term): Account|string
    {
        $subscribers = Subscriber::query()->search($term)->with(['accounts' => fn ($q) => $q->where('status', '<>', AccountStatus::Closed)])->limit(6)->get();
        if ($subscribers->count() > 1) {
            // A name typed in full wins over longer names that contain it.
            $exact = $subscribers->filter(fn (Subscriber $s) => $s->name_search === Arabic::normalize($term));
            $subscribers = $exact->count() === 1 ? $exact : $subscribers;
        }
        if ($subscribers->isEmpty()) {
            // A misheard or misspelt name: the closest names by letter similarity (pg_trgm).
            $name = Arabic::normalize($term);
            $close = Subscriber::query()->select('subscribers.*')->selectRaw('similarity(name_search, ?) as score', [$name])
                ->whereRaw('similarity(name_search, ?) > 0.3', [$name])->orderByDesc('score')->limit(4)
                ->with(['accounts' => fn ($q) => $q->where('status', '<>', AccountStatus::Closed)])->get();
            $best = $close->first();
            $second = $close->get(1);
            // Clearly one person: use it (the reply names them in full). Otherwise ask.
            if ($best && $best->score >= 0.55 && (! $second || $best->score - $second->score >= 0.15)) {
                $subscribers = collect([$best]);
            } elseif ($close->isNotEmpty()) {
                $list = $close->map(fn (Subscriber $s) => "• {$s->full_name}")->implode("\n");

                return "ما لقيت «{$term}» بالضبط. تقصد:\n{$list}\nاكتب الاسم الصحيح.";
            } else {
                return "ما لقيت مشترك باسم «{$term}». اكتب الاسم الكامل أو رقم الهاتف أو اليوزر.";
            }
        }
        if ($subscribers->count() > 1) {
            $list = $subscribers->take(5)->map(fn (Subscriber $s) => "• {$s->full_name}".($s->phone ? " – {$s->phone}" : ''))->implode("\n");

            return "لقيت أكثر من مشترك:\n{$list}\nاكتب الاسم الكامل أو رقم الهاتف.";
        }
        $subscriber = $subscribers->first();
        $accounts = $subscriber->accounts;
        if ($accounts->count() > 1) {
            $byUsername = $accounts->first(fn (Account $a) => mb_strtolower($a->username) === mb_strtolower(trim($term)));
            if ($byUsername) {
                return $byUsername->setRelation('subscriber', $subscriber);
            }

            return "{$subscriber->full_name} عنده أكثر من اشتراك: ".$accounts->pluck('username')->implode('، ').'. اكتب اليوزر بدل الاسم.';
        }
        if ($accounts->isEmpty()) {
            return "{$subscriber->full_name} ما عنده اشتراك فعّال.";
        }

        return $accounts->first()->setRelation('subscriber', $subscriber);
    }

    private function cashBox(User $user): MoneyAccount
    {
        return MoneyAccount::where('kind', MoneyAccountKind::Cash)->where('is_active', true)
            ->orderByRaw('branch_id = ? desc', [$user->branch_id])->orderBy('id')->first()
            ?? throw new BusinessRuleException('لا توجد قاصة نقدية فعالة.');
    }

    private static function category(string $description): ExpenseCategory
    {
        $d = Arabic::normalize($description);
        $name = match (true) {
            (bool) preg_match('/كيبل|كابل|سلك|واير|مواد|شريط|كونيكتر|ربل/u', $d) => 'كابلات ومواد',
            (bool) preg_match('/راوتر|جهاز|اجهزه|ont|مقوي|سويج|switch/u', $d) => 'راوتر وأجهزة',
            default => 'أخرى',
        };

        return ExpenseCategory::firstOrCreate(['name_ar' => $name]);
    }
}

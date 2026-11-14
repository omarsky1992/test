<?php

namespace App\Services;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Models\ServicePlan;
use App\Models\SyncRun;
use App\WhatsApp\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A renewal seen on the company site (days left went from 0 to more than 0). From
 * activation.min_partial_days (4) up to the short activation (7) a sync records it as waiting
 * (`pending_debt`, «المكتشف 7 أيام»): «نعم» makes it one secondary debt (`approveSecondary`), «لا»
 * records none (`declineSecondary`); a WhatsApp activation by an employee is a secondary debt at once.
 * Fewer days is the subscriber's own test and more is a full activation, neither a debt. The debt is
 * owed money shown beside the balance; it changes neither the primary debts nor the cash or company balances.
 */
class RenewalService
{
    public function __construct(private Ledger $ledger, private Sequencer $sequencer, private Audit $audit, private Settings $settings) {}

    /**
     * Up to the short activation (7 days, from the subscriber's app) the renewal is owed as a
     * secondary debt. Longer than that is a full activation: recorded, with no debt.
     */
    public function isFullActivation(int $newDays): bool
    {
        return $newDays > $this->settings->partialDays();
    }

    /**
     * A full activation done before the subscription reached 0 (the company adds it to the days left):
     * the end date moved forward by more than the short activation. A 7-day activation can only be
     * done at 0, and small date corrections stay well under this.
     */
    public function isEarlyFullActivation(int $previousDays, ?\DateTimeInterface $previousEndsAt, ?\DateTimeInterface $newEndsAt): bool
    {
        return $previousDays > 0 && $previousEndsAt !== null && $newEndsAt !== null
            && $newEndsAt->getTimestamp() - $previousEndsAt->getTimestamp() > ($this->settings->partialDays() + 1) * 86400;
    }

    /**
     * Fewer days than the shortest real activation (setting activation.min_partial_days, 4) seen on
     * the company site is a test the subscriber gave themselves: recorded, never a debt.
     */
    public function isSelfTest(int $newDays): bool
    {
        return $newDays < (int) $this->settings->get('activation.min_partial_days');
    }

    /**
     * The longest the activation can be: it started no earlier than the old end date (days must reach
     * 0 first) nor than the last sync that still saw 0. A late sync sees fewer days left than were
     * activated; this bound keeps a short activation seen late from passing for a self-test.
     */
    public static function longestDays(?CarbonImmutable $newEndsAt, ?CarbonImmutable ...$notBefore): ?int
    {
        $starts = array_filter($notBefore);
        if ($newEndsAt === null || $starts === []) {
            return null;
        }
        $start = max(array_map(fn (CarbonImmutable $d) => $d->getTimestamp(), $starts));

        return max(0, (int) round(($newEndsAt->getTimestamp() - $start) / 86400));
    }

    public static function reference(Account $account, ?CarbonImmutable $newEndsAt, CarbonImmutable $detectedAt): string
    {
        // The new end date identifies the renewal: scanning it again gives the same reference.
        return "acc{$account->id}:".($newEndsAt?->utc()->format('YmdHi') ?? 'd'.$detectedAt->format('Ymd'));
    }

    public function record(
        Account $account,
        int $previousDays,
        int $newDays,
        ?CarbonImmutable $previousEndsAt,
        ?CarbonImmutable $newEndsAt,
        ?string $planName = null,
        ?SyncRun $run = null,
        ?CarbonImmutable $at = null,
        bool $fromSite = true,
        ?int $longestDays = null,
        bool $isNew = false,
    ): AccountRenewal {
        $at ??= CarbonImmutable::now();
        $reference = self::reference($account, $newEndsAt, $at);

        if ($existing = AccountRenewal::where('reference', $reference)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($account, $previousDays, $newDays, $previousEndsAt, $newEndsAt, $planName, $run, $at, $reference, $fromSite, $longestDays, $isNew) {
                $plan = $account->current_plan_id ? ServicePlan::find($account->current_plan_id) : null;
                $full = $this->isFullActivation($newDays);
                // Only a sync sees self-tests; an employee who activates by WhatsApp states the days.
                // A test only when even the longest the activation can be is under the minimum.
                $test = $fromSite && ! $full && $this->isSelfTest(max($newDays, $longestDays ?? 0));
                $amount = ! $full && ! $test && $plan?->price > 0 ? (int) $plan->price : null;
                // A short activation a sync found waits for «نعم/لا» before it becomes a debt.
                $pending = $fromSite && ! $full && ! $test;

                $renewal = AccountRenewal::create([
                    'reference' => $reference,
                    'account_id' => $account->id,
                    'subscriber_id' => $account->subscriber_id,
                    'sync_run_id' => $run?->id,
                    'detected_at' => $at,
                    'previous_days' => $previousDays,
                    'new_days' => $newDays,
                    'previous_ends_at' => $previousEndsAt,
                    'new_ends_at' => $newEndsAt,
                    'plan_id' => $plan?->id,
                    'plan_name' => $planName !== null ? mb_substr($planName, 0, 60) : $plan?->name_ar,
                    'amount' => $amount,
                    'is_new' => $isNew,
                    'status' => match (true) {
                        $full => 'activated',
                        $test => 'test',
                        $pending => 'pending_debt',
                        $amount !== null => 'debt_created',
                        default => 'no_price',
                    },
                ]);

                $debt = $amount !== null && ! $pending ? $this->secondaryDebt($renewal, $account, $amount, $at, 'تجديد تلقائي') : null;

                $this->audit->log('renewal.detected', $renewal, ['days_left' => $previousDays, 'ends_at' => $previousEndsAt], [
                    'days_left' => $newDays, 'longest_days' => $longestDays, 'ends_at' => $newEndsAt, 'plan' => $renewal->plan_name, 'amount' => $amount,
                    'debt' => $debt?->number, 'reference' => $reference, 'new' => $isNew ?: null,
                ], match (true) {
                    $full => "تفعيل كامل ({$newDays} يوم): يُسجَّل تفعيلاً بدون دين",
                    $test => "تيست من المشترك ({$newDays} يوم): لا يُسجَّل ديناً",
                    $pending => "تفعيل {$newDays} أيام: ينتظر «نعم/لا» للدين الثانوي (المكتشف 7 أيام)",
                    $amount === null => 'الفئة غير معروفة أو بلا سعر: لم يُنشأ دين',
                    default => null,
                }, $account->subscriber_id, 'sync');

                // A short activation waiting for «نعم/لا» tells the subscriber once it is decided.
                if (! $test && ! $pending) {
                    app(Notifier::class)->renewal($renewal);
                }

                return $renewal;
            });
        } catch (UniqueConstraintViolationException) {
            // Two scans raced on the same renewal; the other one recorded it.
            return AccountRenewal::where('reference', $reference)->firstOrFail();
        }
    }

    /**
     * «المكتشف 7 أيام» → «نعم»: the short activation becomes one secondary debt (the plan price, or the
     * amount typed). Decided once (row lock).
     */
    public function approveSecondary(AccountRenewal $renewal, ?int $amount = null): AccountRenewal
    {
        $this->authorizeDecision();

        return DB::transaction(function () use ($renewal, $amount) {
            $renewal = $this->lockShort($renewal);
            $account = Account::with('currentPlan')->findOrFail($renewal->account_id);
            $amount ??= $renewal->amount ?? ($account->currentPlan?->price > 0 ? (int) $account->currentPlan->price : null);
            if ($amount === null || $amount <= 0) {
                throw new BusinessRuleException('الفئة بلا سعر: اكتب مبلغ الدين.');
            }
            $at = CarbonImmutable::now();
            $debt = $this->secondaryDebt($renewal, $account, $amount, $at, 'المكتشف 7 أيام: نعم');
            $renewal->update(['status' => 'debt_created', 'amount' => $amount, 'decided_at' => $at, 'decided_by' => Auth::id()]);
            $this->audit->log('renewal.secondary_approved', $renewal, ['status' => 'pending_debt'], [
                'status' => 'debt_created', 'debt' => $debt->number, 'amount' => $amount,
            ], 'المكتشف 7 أيام: دين ثانوي', $renewal->subscriber_id);
            app(Notifier::class)->renewal($renewal);

            return $renewal;
        });
    }

    /** «المكتشف 7 أيام» → «لا»: recorded with no debt. Decided once (row lock). */
    public function declineSecondary(AccountRenewal $renewal, ?string $reason = null): AccountRenewal
    {
        $this->authorizeDecision();

        return DB::transaction(function () use ($renewal, $reason) {
            $renewal = $this->lockShort($renewal);
            $renewal->update(['status' => 'no_debt', 'decided_at' => now(), 'decided_by' => Auth::id()]);
            $this->audit->log('renewal.secondary_declined', $renewal, ['status' => 'pending_debt'], ['status' => 'no_debt'],
                $reason ?: 'المكتشف 7 أيام: بدون دين ثانوي', $renewal->subscriber_id);
            app(Notifier::class)->renewal($renewal);

            return $renewal;
        });
    }

    /** Short activations a sync found that wait for «نعم/لا». */
    public static function pendingShort(): Builder
    {
        return AccountRenewal::query()->where('status', 'pending_debt');
    }

    private function lockShort(AccountRenewal $renewal): AccountRenewal
    {
        $locked = AccountRenewal::whereKey($renewal->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending_debt') {
            throw new BusinessRuleException('قُرّر هذا التفعيل مسبقاً ('.($locked->status === 'debt_created' ? 'دين ثانوي' : 'بدون دين').').');
        }

        return $locked;
    }

    private function authorizeDecision(): void
    {
        if (! Auth::user()?->can('activations.recent')) {
            throw new AuthorizationException('ليست لديك صلاحية «المفعّل حديثاً».');
        }
    }

    /** One secondary debt for a short activation, posted against RENEWALS_CLEARING. */
    private function secondaryDebt(AccountRenewal $renewal, Account $account, int $amount, CarbonImmutable $at, string $why): Debt
    {
        $plan = $account->current_plan_id ? ServicePlan::find($account->current_plan_id) : null;
        $debt = Debt::create([
            'number' => $this->sequencer->next('debt', $at),
            'account_id' => $account->id,
            'subscriber_id' => $account->subscriber_id,
            'branch_id' => $account->branch_id,
            'source' => DebtSource::Renewal,
            'bucket' => DebtBucket::Secondary,
            'original_amount' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'debt_date' => $at,
            'status' => DebtStatus::Open,
            'notes' => "تجديد تلقائي من موقع الشركة ({$renewal->previous_days} → {$renewal->new_days} يوم)".($plan ? " – فئة {$plan->name_ar}" : ''),
        ]);
        $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id];
        $this->ledger->post('renewal_debt', $at, [
            ['account' => Ledger::AR_SECONDARY, 'debit' => $amount, ...$tag],
            ['account' => Ledger::RENEWALS_CLEARING, 'credit' => $amount],
        ], $debt, "دين ثانوي – تجديد {$account->username}", $account->branch_id);
        $renewal->update(['debt_id' => $debt->id]);
        $this->audit->log('debt.created', $debt, null, [
            'number' => $debt->number, 'amount' => $amount, 'bucket' => 'secondary', 'source' => 'renewal', 'renewal' => $renewal->reference,
        ], $why, $account->subscriber_id, ...($why === 'تجديد تلقائي' ? ['source' => 'sync'] : []));

        return $debt;
    }

    /**
     * Renewals of more than the short activation that were recorded as debts before the full-activation
     * rule: their debt is voided (only if nothing was paid on it) and they become activations.
     *
     * @return array{fixed: int, kept_paid: int}
     */
    public function reclassifyFullActivations(): array
    {
        $result = ['fixed' => 0, 'kept_paid' => 0];
        $renewals = AccountRenewal::with('debt')->where('status', 'debt_created')->where('new_days', '>', $this->settings->partialDays())->get();
        foreach ($renewals as $renewal) {
            DB::transaction(function () use ($renewal, &$result) {
                $debt = $renewal->debt;
                if ($debt && $debt->status !== DebtStatus::Voided) {
                    if ($debt->paid_amount > 0) {
                        $result['kept_paid']++;

                        return;
                    }
                    app(DebtService::class)->reverseAndVoid($debt, "تفعيل كامل ({$renewal->new_days} يوم): لا يُسجَّل ديناً");
                }
                $renewal->update(['status' => 'activated']);
                $this->audit->log('renewal.reclassified', $renewal, ['status' => 'debt_created'], ['status' => 'activated', 'debt_voided' => $debt?->number], 'تفعيل أكثر من 7 أيام', $renewal->subscriber_id, 'system');
                $result['fixed']++;
            });
        }

        return $result;
    }

    /**
     * Self-tests (fewer than activation.min_partial_days) a sync recorded as debts before the
     * self-test rule: their debt is voided (only if nothing was paid on it) and they become tests.
     *
     * @return array{fixed: int, kept_paid: int}
     */
    public function reclassifySelfTests(): array
    {
        $result = ['fixed' => 0, 'kept_paid' => 0];
        $renewals = AccountRenewal::with('debt')->where('status', 'debt_created')->whereNotNull('sync_run_id')
            ->where('new_days', '<', (int) $this->settings->get('activation.min_partial_days'))->get()
            ->filter(function (AccountRenewal $r) {
                // Had it been active earlier, the previous successful sync would have listed it.
                $before = SyncRun::where('status', 'success')->where('id', '<', $r->sync_run_id)->max('started_at');

                return $this->isSelfTest(max($r->new_days, self::longestDays($r->new_ends_at, $r->previous_ends_at, $before ? CarbonImmutable::parse($before) : null) ?? 0));
            });
        foreach ($renewals as $renewal) {
            DB::transaction(function () use ($renewal, &$result) {
                $debt = $renewal->debt;
                if ($debt && $debt->status !== DebtStatus::Voided) {
                    if ($debt->paid_amount > 0) {
                        $result['kept_paid']++;

                        return;
                    }
                    app(DebtService::class)->reverseAndVoid($debt, "تيست من المشترك ({$renewal->new_days} يوم): لا يُسجَّل ديناً");
                }
                $renewal->update(['status' => 'test']);
                $this->audit->log('renewal.reclassified', $renewal, ['status' => 'debt_created'], ['status' => 'test', 'debt_voided' => $debt?->number], 'تيست من المشترك', $renewal->subscriber_id, 'system');
                $result['fixed']++;
            });
        }

        return $result;
    }
}

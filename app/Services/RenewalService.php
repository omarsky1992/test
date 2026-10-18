<?php

namespace App\Services;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Models\ServicePlan;
use App\Models\SyncRun;
use App\WhatsApp\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A renewal seen on the company site (days left went from 0 to more than 0). From
 * activation.min_partial_days (4) up to the short activation (7) it becomes one secondary debt at
 * the plan price; fewer days is the subscriber's own test and more is a full activation, neither a debt. The debt is owed money shown beside the balance; it
 * changes neither the primary debts nor the cash or company balances.
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
    ): AccountRenewal {
        $at ??= CarbonImmutable::now();
        $reference = self::reference($account, $newEndsAt, $at);

        if ($existing = AccountRenewal::where('reference', $reference)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($account, $previousDays, $newDays, $previousEndsAt, $newEndsAt, $planName, $run, $at, $reference, $fromSite, $longestDays) {
                $plan = $account->current_plan_id ? ServicePlan::find($account->current_plan_id) : null;
                $full = $this->isFullActivation($newDays);
                // Only a sync sees self-tests; an employee who activates by WhatsApp states the days.
                // A test only when even the longest the activation can be is under the minimum.
                $test = $fromSite && ! $full && $this->isSelfTest(max($newDays, $longestDays ?? 0));
                $amount = ! $full && ! $test && $plan?->price > 0 ? (int) $plan->price : null;

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
                    'status' => match (true) {
                        $full => 'activated',
                        $test => 'test',
                        $amount !== null => 'debt_created',
                        default => 'no_price',
                    },
                ]);

                $debt = null;
                if ($amount !== null) {
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
                        'notes' => "تجديد تلقائي من موقع الشركة ({$previousDays} → {$newDays} يوم) – فئة {$plan->name_ar}",
                    ]);
                    $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id];
                    $this->ledger->post('renewal_debt', $at, [
                        ['account' => Ledger::AR_SECONDARY, 'debit' => $amount, ...$tag],
                        ['account' => Ledger::RENEWALS_CLEARING, 'credit' => $amount],
                    ], $debt, "دين ثانوي – تجديد {$account->username}", $account->branch_id);
                    $renewal->update(['debt_id' => $debt->id]);
                }

                $this->audit->log('renewal.detected', $renewal, ['days_left' => $previousDays, 'ends_at' => $previousEndsAt], [
                    'days_left' => $newDays, 'longest_days' => $longestDays, 'ends_at' => $newEndsAt, 'plan' => $renewal->plan_name, 'amount' => $amount,
                    'debt' => $debt?->number, 'reference' => $reference,
                ], match (true) {
                    $full => "تفعيل كامل ({$newDays} يوم): يُسجَّل تفعيلاً بدون دين",
                    $test => "تيست من المشترك ({$newDays} يوم): لا يُسجَّل ديناً",
                    $amount === null => 'الفئة غير معروفة أو بلا سعر: لم يُنشأ دين',
                    default => null,
                }, $account->subscriber_id, 'sync');
                if ($debt !== null) {
                    $this->audit->log('debt.created', $debt, null, [
                        'number' => $debt->number, 'amount' => $amount, 'bucket' => 'secondary', 'source' => 'renewal', 'renewal' => $reference,
                    ], 'تجديد تلقائي', $account->subscriber_id, 'sync');
                }

                if (! $test) {
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

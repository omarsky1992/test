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
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A renewal seen on the company site (days left went from 0 to more than 0). It becomes one
 * secondary debt at the plan price. The debt is owed money shown beside the balance; it
 * changes neither the primary debts nor the cash or company balances.
 */
class RenewalService
{
    public function __construct(private Ledger $ledger, private Sequencer $sequencer, private Audit $audit, private Settings $settings)
    {
    }

    /**
     * Up to the short activation (7 days, from the subscriber's app) the renewal is owed as a
     * secondary debt. Longer than that is a full activation: recorded, with no debt.
     */
    public function isFullActivation(int $newDays): bool
    {
        return $newDays > $this->settings->partialDays();
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
    ): AccountRenewal {
        $at ??= CarbonImmutable::now();
        $reference = self::reference($account, $newEndsAt, $at);

        if ($existing = AccountRenewal::where('reference', $reference)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($account, $previousDays, $newDays, $previousEndsAt, $newEndsAt, $planName, $run, $at, $reference) {
                $plan = $account->current_plan_id ? ServicePlan::find($account->current_plan_id) : null;
                $full = $this->isFullActivation($newDays);
                $amount = ! $full && $plan?->price > 0 ? (int) $plan->price : null;

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
                    'status' => $full ? 'activated' : ($amount !== null ? 'debt_created' : 'no_price'),
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
                    'days_left' => $newDays, 'ends_at' => $newEndsAt, 'plan' => $renewal->plan_name, 'amount' => $amount,
                    'debt' => $debt?->number, 'reference' => $reference,
                ], match (true) {
                    $full => "تفعيل كامل ({$newDays} يوم): يُسجَّل تفعيلاً بدون دين",
                    $amount === null => 'الفئة غير معروفة أو بلا سعر: لم يُنشأ دين',
                    default => null,
                }, $account->subscriber_id, 'sync');
                if ($debt !== null) {
                    $this->audit->log('debt.created', $debt, null, [
                        'number' => $debt->number, 'amount' => $amount, 'bucket' => 'secondary', 'source' => 'renewal', 'renewal' => $reference,
                    ], 'تجديد تلقائي', $account->subscriber_id, 'sync');
                }

                app(\App\WhatsApp\Notifier::class)->renewal($renewal);

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
}

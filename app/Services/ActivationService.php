<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ActivationKind;
use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Enums\PeriodType;
use App\Enums\Settlement;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Activation;
use App\Models\ActivationPeriod;
use App\Models\Debt;
use App\Models\MoneyAccount;
use App\Models\Promotion;
use App\Models\ServicePlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ActivationService
{
    public function __construct(
        private Ledger $ledger,
        private Sequencer $sequencer,
        private Audit $audit,
        private Settings $settings,
        private Pricing $pricing,
    ) {
    }

    /**
     * Records an activation done on the company site. The start is queued after any running
     * subscription; the end is always computed here, never entered.
     */
    public function activate(
        Account $account,
        ServicePlan $plan,
        ActivationKind $kind,
        ?CarbonImmutable $requestedStart = null,
        ?Promotion $promotion = null,
        Settlement $settlement = Settlement::Debt,
        ?int $overridePrice = null,
        ?MoneyAccount $moneyAccount = null,
        ?string $receiverName = null,
        ?string $externalRef = null,
        ?string $notes = null,
        ?int $createdBy = null,
    ): Activation {
        if ($kind === ActivationKind::Partial7 && $settlement !== Settlement::Debt) {
            throw new BusinessRuleException('تفعيل 7 أيام يُسجَّل ديناً ثانوياً دائماً.');
        }
        if ($settlement === Settlement::Paid && $moneyAccount === null) {
            throw new BusinessRuleException('اختر الصندوق أو المحفظة التي استلمت المبلغ.');
        }

        return $this->withOverlapGuard(fn () => DB::transaction(function () use (
            $account, $plan, $kind, $requestedStart, $promotion, $settlement, $overridePrice, $moneyAccount, $receiverName, $externalRef, $notes, $createdBy,
        ) {
            $now = CarbonImmutable::now();
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($account->status !== AccountStatus::Active) {
                throw new BusinessRuleException('لا يمكن تفعيل حساب غير فعّال.');
            }

            $price = $this->pricing->quote($plan, $promotion, $overridePrice);
            if ($price['final_price'] <= 0) {
                throw new BusinessRuleException('السعر النهائي يجب أن يكون أكبر من صفر.');
            }

            $requested = $requestedStart ?? $now;
            $lastEnd = $this->lastEnd($account);
            $startsAt = $lastEnd !== null && $lastEnd->greaterThan($requested) ? $lastEnd : $requested;
            $days = $kind === ActivationKind::Partial7 ? $this->settings->partialDays() : $this->settings->fullDays();
            $endsAt = $startsAt->addDays($days);

            $activation = Activation::create([
                'number' => $this->sequencer->next('activation', $now),
                'account_id' => $account->id,
                'subscriber_id' => $account->subscriber_id,
                'branch_id' => $account->branch_id,
                'plan_id' => $plan->id,
                'promotion_id' => $overridePrice === null ? $promotion?->id : null,
                'kind' => $kind,
                ...$price,
                'currency_code' => 'IQD',
                'settlement' => $settlement,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'completion_status' => $kind === ActivationKind::Partial7 ? CompletionStatus::Pending : CompletionStatus::NotRequired,
                'start_overridden' => $requestedStart !== null && abs($requestedStart->diffInSeconds($now)) > 60,
                'external_ref' => $externalRef,
                'status' => DocumentStatus::Posted,
                'notes' => $notes,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            ActivationPeriod::create([
                'activation_id' => $activation->id,
                'account_id' => $account->id,
                'period_type' => $kind === ActivationKind::Partial7 ? PeriodType::Initial7 : PeriodType::Initial30,
                'days' => $days,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'source_type' => 'activation',
                'source_id' => $activation->id,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            $bucket = $kind === ActivationKind::Partial7 ? DebtBucket::Secondary : DebtBucket::Primary;
            $debt = Debt::create([
                'number' => $this->sequencer->next('debt', $now),
                'account_id' => $account->id,
                'subscriber_id' => $account->subscriber_id,
                'branch_id' => $account->branch_id,
                'source' => DebtSource::Activation,
                'activation_id' => $activation->id,
                'bucket' => $bucket,
                'original_amount' => $price['final_price'],
                'paid_amount' => 0,
                'balance' => $price['final_price'],
                'currency_code' => 'IQD',
                'debt_date' => $startsAt->lessThan($now) ? $startsAt : $now,
                'status' => DebtStatus::Open,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id];
            $this->ledger->post('activation_charge', $now, [
                ['account' => $this->arCode($bucket), 'debit' => $price['final_price'], ...$tag],
                ['account' => Ledger::EXP_PROMO_DISCOUNT, 'debit' => $price['company_cost'] - $price['final_price']],
                ['account' => $this->companyMoneyAccount($account->branch_id)->ledger_account_id, 'credit' => $price['company_cost']],
            ], $activation, "تفعيل {$activation->number}", $account->branch_id);

            $account->update(['current_plan_id' => $plan->id]);
            $this->refreshAccountEnd($account);

            $this->audit->log('activation.created', $activation, null, [
                'number' => $activation->number, 'kind' => $kind->value, 'plan' => $plan->code,
                'starts_at' => $startsAt->format(DATE_ATOM), 'ends_at' => $endsAt->format(DATE_ATOM),
                'final_price' => $price['final_price'], 'debt' => $debt->number,
            ], subscriberId: $account->subscriber_id);

            if ($settlement === Settlement::Paid) {
                app(PaymentService::class)->record(
                    account: $account,
                    amount: $price['final_price'],
                    method: $moneyAccount->kind === MoneyAccountKind::Electronic ? PaymentMethod::Electronic : PaymentMethod::Cash,
                    moneyAccount: $moneyAccount,
                    receiverName: $receiverName ?? $moneyAccount->holder_name,
                    debtIds: [$debt->id],
                    notes: "تسديد التفعيل {$activation->number}",
                );
            } elseif ($settlement === Settlement::Credit) {
                app(PaymentService::class)->applyCredit($account, $debt->fresh());
            }

            return $activation->fresh();
        }));
    }

    /**
     * Adds the remaining days (23) to a pending 7-day activation. Starts at the completion time,
     * but never before the 7 days end, so a completion inside the 7 days gives exactly 30 days.
     */
    public function complete(Activation $activation, CompletedVia $via, CarbonImmutable $at, string $sourceType, ?int $sourceId): ?ActivationPeriod
    {
        return $this->withOverlapGuard(fn () => DB::transaction(function () use ($activation, $via, $at, $sourceType, $sourceId) {
            $activation = Activation::whereKey($activation->id)->lockForUpdate()->firstOrFail();
            if ($activation->completion_status !== CompletionStatus::Pending || $activation->status !== DocumentStatus::Posted) {
                return null;
            }

            $days = $this->settings->extensionDays();
            $start = $activation->ends_at->greaterThan($at) ? $activation->ends_at : $at;

            $later = ActivationPeriod::where('account_id', $activation->account_id)
                ->where('activation_id', '<>', $activation->id)
                ->where('is_void', false)
                ->where('ends_at', '>', $activation->ends_at)
                ->orderByDesc('starts_at')
                ->get();

            if ($later->contains(fn (ActivationPeriod $p) => $p->starts_at->lessThanOrEqualTo($at))) {
                // A newer subscription is already running: the extension goes after the whole queue.
                $start = $this->lastEnd($activation->account);
            } elseif ($later->isNotEmpty()) {
                // Queued renewals move back so the extension fits before them.
                $shift = $start->addDays($days)->getTimestamp() - $later->last()->starts_at->getTimestamp();
                if ($shift > 0) {
                    foreach ($later as $period) {
                        $period->update(['starts_at' => $period->starts_at->addSeconds($shift), 'ends_at' => $period->ends_at->addSeconds($shift)]);
                    }
                    foreach ($later->pluck('activation_id')->unique() as $id) {
                        $this->refreshActivationDates(Activation::find($id));
                    }
                }
            }

            $period = ActivationPeriod::create([
                'activation_id' => $activation->id,
                'account_id' => $activation->account_id,
                'period_type' => PeriodType::Extension23,
                'days' => $days,
                'starts_at' => $start,
                'ends_at' => $start->addDays($days),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_by' => Auth::id(),
            ]);

            $activation->update([
                'completion_status' => CompletionStatus::Completed,
                'completed_at' => $at,
                'completed_via' => $via,
                'ends_at' => $period->ends_at,
            ]);
            $this->refreshAccountEnd($activation->account);

            $this->audit->log('activation.completed', $activation, null, [
                'via' => $via->value, 'days' => $days, 'starts_at' => $start->format(DATE_ATOM), 'ends_at' => $period->ends_at->format(DATE_ATOM),
            ], subscriberId: $activation->subscriber_id);

            return $period;
        }));
    }

    /**
     * Moves the start of the latest activation on an account; all its periods move with it.
     */
    public function editStart(Activation $activation, CarbonImmutable $newStart, string $reason): Activation
    {
        return $this->withOverlapGuard(fn () => DB::transaction(function () use ($activation, $newStart, $reason) {
            $activation = Activation::whereKey($activation->id)->lockForUpdate()->firstOrFail();
            $periods = $activation->periods()->where('is_void', false)->orderBy('starts_at')->get();

            $hasLater = ActivationPeriod::where('account_id', $activation->account_id)
                ->where('activation_id', '<>', $activation->id)->where('is_void', false)
                ->where('starts_at', '>=', $activation->ends_at)->exists();
            if ($hasLater) {
                throw new BusinessRuleException('يمكن تعديل وقت البداية لآخر تفعيل على الحساب فقط.');
            }

            $shift = $newStart->getTimestamp() - $activation->starts_at->getTimestamp();
            $ordered = $shift > 0 ? $periods->reverse() : $periods;
            foreach ($ordered as $period) {
                $period->update(['starts_at' => $period->starts_at->addSeconds($shift), 'ends_at' => $period->ends_at->addSeconds($shift)]);
            }

            $old = ['starts_at' => $activation->starts_at->format(DATE_ATOM), 'ends_at' => $activation->ends_at->format(DATE_ATOM)];
            $activation->update(['start_overridden' => true]);
            $this->refreshActivationDates($activation);
            $this->refreshAccountEnd($activation->account);

            $this->audit->log('activation.start_edited', $activation, $old, [
                'starts_at' => $activation->starts_at->format(DATE_ATOM), 'ends_at' => $activation->ends_at->format(DATE_ATOM),
            ], $reason, $activation->subscriber_id);

            return $activation;
        }));
    }

    /**
     * Correction of an activation recorded by mistake: voids its periods and its debt, and gives
     * the company balance back. Refused while the debt still has live payments.
     */
    public function void(Activation $activation, string $reason): Activation
    {
        return DB::transaction(function () use ($activation, $reason) {
            $activation = Activation::whereKey($activation->id)->lockForUpdate()->firstOrFail();
            if ($activation->status === DocumentStatus::Voided) {
                throw new BusinessRuleException('التفعيل ملغى مسبقاً.');
            }

            $debt = $activation->debt;
            if ($debt !== null) {
                app(DebtService::class)->reverseAndVoid($debt, $reason);
            }

            $activation->periods()->update(['is_void' => true]);
            $activation->update(['status' => DocumentStatus::Voided, 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason]);
            $this->refreshAccountEnd($activation->account);

            $this->audit->log('activation.voided', $activation, null, ['number' => $activation->number], $reason, $activation->subscriber_id);

            return $activation;
        });
    }

    public function lastEnd(Account $account): ?CarbonImmutable
    {
        $end = ActivationPeriod::where('account_id', $account->id)->where('is_void', false)->max('ends_at');

        return $end ? CarbonImmutable::parse($end) : null;
    }

    public function refreshAccountEnd(Account $account): void
    {
        $account->update(['service_ends_at' => $this->lastEnd($account)]);
    }

    public function companyMoneyAccount(int $branchId): MoneyAccount
    {
        return MoneyAccount::where('branch_id', $branchId)->where('kind', MoneyAccountKind::Company)->where('is_active', true)->first()
            ?? throw new BusinessRuleException('لا يوجد صندوق «رصيد الشركة» لهذا الفرع. أضفه من الصناديق.');
    }

    public function arCode(DebtBucket $bucket): string
    {
        return $bucket === DebtBucket::Secondary ? Ledger::AR_SECONDARY : Ledger::AR_PRIMARY;
    }

    private function refreshActivationDates(Activation $activation): void
    {
        $periods = $activation->periods()->where('is_void', false);
        $activation->update(['starts_at' => $periods->min('starts_at'), 'ends_at' => $periods->max('ends_at')]);
    }

    /**
     * Turns the database's no-overlap constraint into a readable message.
     */
    private function withOverlapGuard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'activation_periods_no_overlap')) {
                throw new BusinessRuleException('الفترة تتداخل مع اشتراك آخر على نفس الحساب.', previous: $e);
            }
            throw $e;
        }
    }
}

<?php

namespace App\Services;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «المفعّل حديثاً»: a full activation a sync found on the company site is recorded with no debt and
 * waits here until someone says how it was paid: «دين» adds one primary debt (the subscriber stays
 * «فعّال وعليه دين»), «نقد» keeps it active with no debt, and «مناقلة» moves the subscriber's unpaid
 * secondary debts to the primary debts (the 30 days were given on that debt). Each one is decided once.
 */
class RecentActivationService
{
    public function __construct(private DebtService $debts, private Audit $audit) {}

    public static function pending(): Builder
    {
        return AccountRenewal::query()->where('status', 'activated')->whereNotNull('sync_run_id')->whereNull('decision');
    }

    public function asDebt(AccountRenewal $renewal, ?int $amount = null): AccountRenewal
    {
        $this->authorize();

        return DB::transaction(function () use ($renewal, $amount) {
            $renewal = $this->lockPending($renewal);
            $account = Account::with('currentPlan')->findOrFail($renewal->account_id);
            $amount ??= $account->currentPlan?->price > 0 ? (int) $account->currentPlan->price : null;
            if ($amount === null || $amount <= 0) {
                throw new BusinessRuleException('الفئة بلا سعر: اكتب مبلغ الدين.');
            }
            $debt = $this->debts->create($account, $amount, DebtBucket::Primary, DebtSource::Manual,
                notes: "تفعيل {$renewal->new_days} يوم من موقع الشركة (المفعّل حديثاً)");
            $renewal->update(['decision' => 'debt', 'decided_at' => now(), 'decided_by' => Auth::id(), 'decision_debt_id' => $debt->id]);
            $this->audit->log('renewal.decided', $renewal, ['decision' => null], [
                'decision' => 'debt', 'debt' => $debt->number, 'amount' => $amount,
            ], 'المفعّل حديثاً: دين أولي', $renewal->subscriber_id);

            return $renewal;
        });
    }

    /** The account's unpaid secondary debts, oldest first. */
    public static function secondaryDebts(int $accountId): Builder
    {
        return Debt::query()->where('account_id', $accountId)->where('bucket', DebtBucket::Secondary)
            ->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->where('balance', '>', 0)->orderBy('debt_date')->orderBy('id');
    }

    public function asTransfer(AccountRenewal $renewal): AccountRenewal
    {
        $this->authorize();
        if (! Auth::user()->can('transfers.create')) {
            throw new AuthorizationException('ليست لديك صلاحية المناقلة.');
        }

        return DB::transaction(function () use ($renewal) {
            $renewal = $this->lockPending($renewal);
            $debts = self::secondaryDebts($renewal->account_id)->get();
            if ($debts->isEmpty()) {
                throw new BusinessRuleException('لا يوجد دين ثانوي غير مسدد على هذا المشترك.');
            }
            $transfers = $debts->map(fn (Debt $debt) => $this->debts->transfer($debt, "تفعيل {$renewal->new_days} يوم من موقع الشركة (المفعّل حديثاً)"));
            $renewal->update(['decision' => 'transfer', 'decided_at' => now(), 'decided_by' => Auth::id(), 'decision_debt_id' => $debts->first()->id]);
            $this->audit->log('renewal.decided', $renewal, ['decision' => null], [
                'decision' => 'transfer', 'transfers' => $transfers->pluck('number')->all(), 'amount' => (int) $transfers->sum('amount'),
            ], 'المفعّل حديثاً: مناقلة الدين الثانوي إلى الأولي', $renewal->subscriber_id);

            return $renewal;
        });
    }

    public function asCash(AccountRenewal $renewal): AccountRenewal
    {
        $this->authorize();

        return DB::transaction(function () use ($renewal) {
            $renewal = $this->lockPending($renewal);
            $renewal->update(['decision' => 'cash', 'decided_at' => now(), 'decided_by' => Auth::id()]);
            $this->audit->log('renewal.decided', $renewal, ['decision' => null], ['decision' => 'cash'], 'المفعّل حديثاً: نقد – فعّال بدون دين', $renewal->subscriber_id);

            return $renewal;
        });
    }

    private function lockPending(AccountRenewal $renewal): AccountRenewal
    {
        $locked = AccountRenewal::whereKey($renewal->id)->lockForUpdate()->firstOrFail();
        if ($locked->decision !== null) {
            throw new BusinessRuleException('سُجّل هذا التفعيل مسبقاً ('.(['debt' => 'دين', 'cash' => 'نقد', 'transfer' => 'مناقلة'][$locked->decision] ?? $locked->decision).').');
        }
        if ($locked->status !== 'activated' || $locked->sync_run_id === null) {
            throw new BusinessRuleException('هذا ليس تفعيلاً كاملاً من المزامنة.');
        }

        return $locked;
    }

    private function authorize(): void
    {
        if (! Auth::user()?->can('activations.recent')) {
            throw new AuthorizationException('ليست لديك صلاحية «المفعّل حديثاً».');
        }
    }
}

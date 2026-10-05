<?php

namespace App\Services;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\ActivationDue;
use App\Models\Debt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * «يجب التفعيل»: a subscriber who paid a secondary debt in full is waiting for the full activation
 * on the company site. The list closes itself when the company end date moves forward (sync or a
 * WhatsApp activation), or the employee confirms «تم التفعيل».
 */
class ActivationDueService
{
    /** The company end date must move at least this far past where it was when paid. */
    private const MOVED_DAYS = 2;

    public function __construct(private Audit $audit, private Settings $settings)
    {
    }

    /**
     * Called whenever a payment, a credit or a voided receipt changes a debt.
     */
    public function debtChanged(Debt $debt, DebtStatus $before): void
    {
        if ($debt->bucket !== DebtBucket::Secondary || $before === $debt->status) {
            return;
        }
        if ($debt->status === DebtStatus::Paid) {
            $this->open($debt);
        } elseif ($before === DebtStatus::Paid) {
            $this->cancel($debt);
        }
    }

    public function accountEndsChanged(Account $account): void
    {
        $ends = $account->external_ends_at;
        if ($ends === null) {
            return;
        }
        $dues = ActivationDue::where('account_id', $account->id)->where('status', 'pending')->get();
        foreach ($dues as $due) {
            $reference = $due->ends_at_when_paid ?? $due->paid_at;
            if ($ends->greaterThan($reference->addDays(self::MOVED_DAYS))) {
                $due->update(['status' => 'done', 'resolved_via' => 'sync', 'resolved_at' => now(), 'resolved_by' => null]);
                $this->audit->log('activation_due.done', $due, ['status' => 'pending'], [
                    'status' => 'done', 'via' => 'company', 'ends_at' => $ends,
                ], 'اكتُشف التفعيل في موقع الشركة', $due->subscriber_id, 'system');
            }
        }
    }

    public function markDone(ActivationDue $due): ActivationDue
    {
        if ($due->status !== 'pending') {
            throw new BusinessRuleException('هذا المشترك ليس في قائمة «يجب التفعيل».');
        }
        $due->update(['status' => 'done', 'resolved_via' => 'manual', 'resolved_at' => now(), 'resolved_by' => Auth::id()]);
        $this->audit->log('activation_due.done', $due, ['status' => 'pending'], ['status' => 'done', 'via' => 'manual'], 'تم التفعيل (تأكيد الموظف)', $due->subscriber_id);

        return $due;
    }

    private function open(Debt $debt): void
    {
        $account = Account::findOrFail($debt->account_id);
        // Already more than a short activation left on the company site: it was activated before the payment.
        $left = Account::daysLeft($account->external_ends_at);
        if ($left !== null && $left > $this->settings->partialDays()) {
            return;
        }
        $due = ActivationDue::firstOrNew(['debt_id' => $debt->id]);
        if ($due->exists && $due->status === 'pending') {
            return;
        }
        $due->fill([
            'account_id' => $debt->account_id,
            'subscriber_id' => $debt->subscriber_id,
            'amount' => $debt->original_amount,
            'paid_at' => CarbonImmutable::now(),
            'ends_at_when_paid' => $account->external_ends_at,
            'status' => 'pending',
            'resolved_via' => null,
            'resolved_at' => null,
            'resolved_by' => null,
        ])->save();
        $this->audit->log('activation_due.opened', $due, null, ['debt' => $debt->number, 'amount' => $debt->original_amount], 'سدّد الدين الثانوي: يجب التفعيل', $debt->subscriber_id);
    }

    private function cancel(Debt $debt): void
    {
        $due = ActivationDue::where('debt_id', $debt->id)->where('status', 'pending')->first();
        if ($due) {
            $due->update(['status' => 'cancelled', 'resolved_via' => 'void', 'resolved_at' => now(), 'resolved_by' => Auth::id()]);
            $this->audit->log('activation_due.cancelled', $due, ['status' => 'pending'], ['status' => 'cancelled'], 'أُلغي السند فعاد الدين غير مسدد', $debt->subscriber_id);
        }
    }
}

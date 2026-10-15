<?php

namespace App\Services;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Models\FundTransfer;
use App\Models\MoneyAccount;
use App\Models\MoneyAccountReset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Moving money inside the system: from one employee's custody to another's, from one card or wallet
 * to another, and zeroing a card. None of these is income or an expense, and none touches a
 * subscriber's debt. Each checks its own permission here, not only in the screens.
 */
class InternalTransfers
{
    public function __construct(private TreasuryService $treasury, private EmployeeFinance $employees) {}

    public function betweenEmployees(User $from, User $to, int $amount, ?string $notes = null, ?string $idempotencyKey = null): FundTransfer
    {
        $this->authorize('custody_transfers.create');
        if ($from->is($to)) {
            throw new BusinessRuleException('لا يمكن التحويل إلى حساب الموظف نفسه.');
        }

        return $this->treasury->transfer($this->employees->custodyAccount($from), $this->employees->custodyAccount($to), $amount,
            null, $notes, $idempotencyKey);
    }

    public function betweenCards(MoneyAccount $from, MoneyAccount $to, int $amount, ?string $notes = null, ?string $reference = null, ?string $idempotencyKey = null): FundTransfer
    {
        $this->authorize('cards.transfer');
        $this->ensureCard($from);
        $this->ensureCard($to);

        return $this->treasury->transfer($from, $to, $amount, $reference, $notes, $idempotencyKey);
    }

    public function resetCard(MoneyAccount $card, string $reason, ?string $idempotencyKey = null): MoneyAccountReset
    {
        $this->authorize('cards.reset');
        $this->ensureCard($card);

        return $this->treasury->resetBalance($card, $reason, $idempotencyKey);
    }

    public function void(FundTransfer $transfer, string $reason): FundTransfer
    {
        $this->authorize(match ($transfer->kind) {
            'custody' => 'custody_transfers.void',
            'card' => 'cards.void',
            default => 'funds.transfer',
        });

        return $this->treasury->voidTransfer($transfer, $reason);
    }

    public function voidReset(MoneyAccountReset $reset, string $reason): MoneyAccountReset
    {
        $this->authorize('cards.void');

        return $this->treasury->voidReset($reset, $reason);
    }

    private function ensureCard(MoneyAccount $box): void
    {
        if ($box->kind !== MoneyAccountKind::Electronic) {
            throw new BusinessRuleException("«{$box->name}» ليس حساب بطاقة.");
        }
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()?->can($permission)) {
            throw new AuthorizationException('ليست لديك صلاحية لهذا الإجراء.');
        }
    }
}

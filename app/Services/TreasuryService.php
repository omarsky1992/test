<?php

namespace App\Services;

use App\Enums\DistributionKind;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Activation;
use App\Models\Debt;
use App\Models\DeviceSale;
use App\Models\DeviceType;
use App\Models\CompanySettlement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialTransaction;
use App\Models\FundTransfer;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\SettlementDistribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cash boxes, wallets and the company balance: opening balances, transfers between them,
 * the company's commission (الراجع) and its distribution, and expenses.
 */
class TreasuryService
{
    public function __construct(private Ledger $ledger, private Sequencer $sequencer, private Audit $audit)
    {
    }

    public function createMoneyAccount(int $branchId, MoneyAccountKind $kind, string $name, ?string $holderName = null, ?int $userId = null): MoneyAccount
    {
        return DB::transaction(function () use ($branchId, $kind, $name, $holderName, $userId) {
            $prefix = match ($kind) {
                MoneyAccountKind::Cash => 'CASH',
                MoneyAccountKind::Electronic => 'WALLET',
                MoneyAccountKind::Company => 'COMPANY',
                MoneyAccountKind::Custody => 'CUSTODY',
            };
            $ledgerAccount = LedgerAccount::create([
                'code' => $prefix.':'.uniqid(),
                'name_ar' => $name,
                'type' => 'asset',
                'branch_id' => $branchId,
                'is_system' => false,
            ]);
            $ledgerAccount->update(['code' => "{$prefix}:{$ledgerAccount->id}"]);

            $moneyAccount = MoneyAccount::create([
                'branch_id' => $branchId,
                'kind' => $kind,
                'name' => $name,
                'holder_name' => $holderName,
                'user_id' => $userId,
                'ledger_account_id' => $ledgerAccount->id,
            ]);
            $this->audit->log('money_account.created', $moneyAccount, null, ['name' => $name, 'kind' => $kind->value]);

            return $moneyAccount;
        });
    }

    public function balance(MoneyAccount $moneyAccount): int
    {
        return $this->ledger->balance($moneyAccount->ledger_account_id);
    }

    public function openingBalance(MoneyAccount $moneyAccount, int $amount, ?string $notes = null): FinancialTransaction
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('الرصيد الابتدائي يجب أن يكون أكبر من صفر.');
        }

        $txn = $this->ledger->post('opening_balance', CarbonImmutable::now(), [
            ['account' => $moneyAccount->ledger_account_id, 'debit' => $amount],
            ['account' => Ledger::OPENING_EQUITY, 'credit' => $amount],
        ], $moneyAccount, $notes ?? "رصيد ابتدائي: {$moneyAccount->name}", $moneyAccount->branch_id);
        $this->audit->log('money_account.opening_balance', $moneyAccount, null, ['amount' => $amount]);

        return $txn;
    }

    /**
     * Moves money between two boxes, e.g. topping up the company balance from the cash box.
     */
    public function transfer(MoneyAccount $from, MoneyAccount $to, int $amount, ?string $reference = null, ?string $notes = null): FundTransfer
    {
        if ($from->is($to)) {
            throw new BusinessRuleException('اختر صندوقين مختلفين.');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $reference, $notes) {
            $now = CarbonImmutable::now();
            $this->ensureCovers($from, $amount);
            $transfer = FundTransfer::create([
                'number' => $this->sequencer->next('fund_transfer', $now),
                'from_money_account_id' => $from->id,
                'to_money_account_id' => $to->id,
                'amount' => $amount,
                'transferred_at' => $now,
                'reference' => $reference,
                'status' => DocumentStatus::Posted,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $txn = $this->ledger->post('fund_transfer', $now, [
                ['account' => $to->ledger_account_id, 'debit' => $amount],
                ['account' => $from->ledger_account_id, 'credit' => $amount],
            ], $transfer, "تحويل {$transfer->number}", $from->branch_id);
            $transfer->update(['txn_id' => $txn->id]);
            $this->audit->log('fund_transfer.created', $transfer, null, ['from' => $from->name, 'to' => $to->name, 'amount' => $amount]);

            return $transfer;
        });
    }

    /**
     * Records the commission the company pays for a period. This is the agent's revenue from activations.
     */
    public function recordSettlement(int $amount, string $periodFrom, string $periodTo, MoneyAccount $into, ?string $notes = null): CompanySettlement
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($amount, $periodFrom, $periodTo, $into, $notes) {
            $now = CarbonImmutable::now();
            $count = Activation::where('status', DocumentStatus::Posted)
                ->whereBetween('created_at', [CarbonImmutable::parse($periodFrom)->startOfDay(), CarbonImmutable::parse($periodTo)->endOfDay()])
                ->count();

            $settlement = CompanySettlement::create([
                'number' => $this->sequencer->next('settlement', $now),
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'amount' => $amount,
                'received_into_money_account_id' => $into->id,
                'received_at' => $now,
                'activations_count' => $count,
                'status' => DocumentStatus::Posted,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $txn = $this->ledger->post('company_settlement', $now, [
                ['account' => $into->ledger_account_id, 'debit' => $amount],
                ['account' => Ledger::REV_COMMISSION, 'credit' => $amount],
            ], $settlement, "راجع الشركة {$settlement->number}", $into->branch_id);
            $settlement->update(['txn_id' => $txn->id]);
            $this->audit->log('settlement.created', $settlement, null, ['amount' => $amount, 'from' => $periodFrom, 'to' => $periodTo]);

            return $settlement;
        });
    }

    /**
     * Splits the commission: a share to the zone fund (an internal move) and shares paid out to partners or salaries.
     */
    public function distribute(CompanySettlement $settlement, DistributionKind $kind, int $amount, MoneyAccount $from, ?MoneyAccount $to = null, ?string $beneficiary = null): SettlementDistribution
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
        }
        if ($kind === DistributionKind::ZoneFund && $to === null) {
            throw new BusinessRuleException('اختر صندوق الزون.');
        }

        return DB::transaction(function () use ($settlement, $kind, $amount, $from, $to, $beneficiary) {
            $settlement = CompanySettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $distributed = (int) $settlement->distributions()->sum('amount');
            if ($distributed + $amount > $settlement->amount) {
                throw new BusinessRuleException('مجموع التوزيعات أكبر من مبلغ الراجع.');
            }
            $this->ensureCovers($from, $amount);

            $distribution = SettlementDistribution::create([
                'settlement_id' => $settlement->id,
                'kind' => $kind,
                'beneficiary' => $beneficiary,
                'amount' => $amount,
                'from_money_account_id' => $from->id,
                'to_money_account_id' => $kind === DistributionKind::ZoneFund ? $to->id : null,
                'created_by' => Auth::id(),
            ]);
            $debit = $kind === DistributionKind::ZoneFund ? $to->ledger_account_id : Ledger::DISTRIBUTIONS;
            $txn = $this->ledger->post('distribution', CarbonImmutable::now(), [
                ['account' => $debit, 'debit' => $amount],
                ['account' => $from->ledger_account_id, 'credit' => $amount],
            ], $distribution, "توزيع راجع {$settlement->number}", $from->branch_id);
            $distribution->update(['txn_id' => $txn->id]);
            $this->audit->log('settlement.distributed', $settlement, null, ['kind' => $kind->value, 'amount' => $amount, 'beneficiary' => $beneficiary]);

            return $distribution;
        });
    }

    public function recordExpense(ExpenseCategory $category, string $description, int $amount, MoneyAccount $from, ?string $paidTo = null, ?string $notes = null, bool $allowNegative = false): Expense
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($category, $description, $amount, $from, $paidTo, $notes, $allowNegative) {
            $now = CarbonImmutable::now();
            if (! $allowNegative) {
                $this->ensureCovers($from, $amount);
            }
            $expense = Expense::create([
                'number' => $this->sequencer->next('expense', $now),
                'branch_id' => $from->branch_id,
                'category_id' => $category->id,
                'description' => $description,
                'amount' => $amount,
                'money_account_id' => $from->id,
                'paid_to' => $paidTo,
                'spent_at' => $now,
                'status' => DocumentStatus::Posted,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $txn = $this->ledger->post('expense', $now, [
                ['account' => Ledger::EXP_GENERAL, 'debit' => $amount],
                ['account' => $from->ledger_account_id, 'credit' => $amount],
            ], $expense, "مصروف {$expense->number}", $from->branch_id);
            $expense->update(['txn_id' => $txn->id]);
            $this->audit->log('expense.created', $expense, null, ['amount' => $amount, 'category' => $category->name_ar, 'description' => $description]);

            return $expense;
        });
    }

    public function voidExpense(Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->status === DocumentStatus::Voided) {
                throw new BusinessRuleException('المصروف ملغى مسبقاً.');
            }
            $this->ledger->reverse(FinancialTransaction::findOrFail($expense->txn_id), CarbonImmutable::now(), "إلغاء المصروف {$expense->number}");
            $expense->update(['status' => DocumentStatus::Voided, 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason]);
            $this->audit->log('expense.voided', $expense, null, ['number' => $expense->number], $reason);

            return $expense;
        });
    }

    /**
     * Sells a device (router, ONT…): paid now into a box, or as a primary debt on an account.
     * The profit (price − cost) reaches the balance through the ledger.
     */
    public function recordDeviceSale(
        DeviceType $type,
        string $description,
        int $unitPrice,
        int $quantity = 1,
        ?int $cost = null,
        ?MoneyAccount $into = null,
        ?Account $account = null,
        ?string $buyerName = null,
        ?string $serial = null,
        ?string $notes = null,
    ): DeviceSale {
        if ($unitPrice <= 0 || $quantity <= 0) {
            throw new BusinessRuleException('السعر والكمية يجب أن يكونا أكبر من صفر.');
        }
        if ($into === null && $account === null) {
            throw new BusinessRuleException('اختر صندوق الاستلام، أو حساب المشترك للبيع بالدين.');
        }
        if ($into?->kind === MoneyAccountKind::Company) {
            throw new BusinessRuleException('لا يُستلم ثمن البيع في رصيد الشركة.');
        }

        return DB::transaction(function () use ($type, $description, $unitPrice, $quantity, $cost, $into, $account, $buyerName, $serial, $notes) {
            $now = CarbonImmutable::now();
            $total = $unitPrice * $quantity;
            $sale = DeviceSale::create([
                'number' => $this->sequencer->next('sale', $now),
                'branch_id' => $into?->branch_id ?? $account->branch_id,
                'subscriber_id' => $account?->subscriber_id,
                'account_id' => $account?->id,
                'buyer_name' => $buyerName,
                'item_type_id' => $type->id,
                'description' => $description,
                'serial_number' => $serial,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $total,
                'cost_amount' => $cost,
                'settlement' => $into ? 'paid' : 'debt',
                'money_account_id' => $into?->id,
                'sold_at' => $now,
                'status' => DocumentStatus::Posted,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            if ($into) {
                $lines = [
                    ['account' => $into->ledger_account_id, 'debit' => $total],
                    ['account' => Ledger::REV_DEVICE_SALES, 'credit' => $total],
                ];
            } else {
                $debt = Debt::create([
                    'number' => $this->sequencer->next('debt', $now),
                    'account_id' => $account->id,
                    'subscriber_id' => $account->subscriber_id,
                    'branch_id' => $account->branch_id,
                    'source' => DebtSource::DeviceSale,
                    'device_sale_id' => $sale->id,
                    'bucket' => DebtBucket::Primary,
                    'original_amount' => $total,
                    'paid_amount' => 0,
                    'balance' => $total,
                    'debt_date' => $now,
                    'status' => DebtStatus::Open,
                    'notes' => "بيع {$description}",
                    'created_by' => Auth::id(),
                ]);
                $lines = [
                    ['account' => Ledger::AR_PRIMARY, 'debit' => $total, 'subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id],
                    ['account' => Ledger::REV_DEVICE_SALES, 'credit' => $total],
                ];
            }
            $txn = $this->ledger->post('device_sale', $now, $lines, $sale, "بيع {$sale->number}", $sale->branch_id);
            $sale->update(['txn_id' => $txn->id]);
            $this->audit->log('sale.created', $sale, null, ['description' => $description, 'total' => $total, 'settlement' => $sale->settlement], subscriberId: $account?->subscriber_id);

            return $sale;
        });
    }

    public function voidDeviceSale(DeviceSale $sale, string $reason): DeviceSale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $sale = DeviceSale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === DocumentStatus::Voided) {
                throw new BusinessRuleException('البيع ملغى مسبقاً.');
            }
            $debt = Debt::where('device_sale_id', $sale->id)->first();
            if ($debt && $debt->paid_amount > 0) {
                throw new BusinessRuleException('على دين هذا البيع تسديدات. ألغِ سندات القبض أولاً.');
            }
            $this->ledger->reverse(FinancialTransaction::findOrFail($sale->txn_id), CarbonImmutable::now(), "إلغاء البيع {$sale->number}");
            $debt?->update(['status' => DebtStatus::Voided, 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason]);
            $sale->update(['status' => DocumentStatus::Voided, 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason]);
            $this->audit->log('sale.voided', $sale, null, ['number' => $sale->number], $reason, $sale->subscriber_id);

            return $sale;
        });
    }

    public function ensureCovers(MoneyAccount $from, int $amount): void
    {
        if ($from->kind !== MoneyAccountKind::Company && $this->balance($from) < $amount) {
            throw new BusinessRuleException("رصيد «{$from->name}» غير كافٍ.");
        }
    }
}

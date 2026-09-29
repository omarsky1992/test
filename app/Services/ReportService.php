<?php

namespace App\Services;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Models\Activation;
use App\Models\AuditLog;
use App\Models\CompanySettlement;
use App\Models\Debt;
use App\Models\DebtTransfer;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the dashboard and the daily report. Days are Baghdad calendar days.
 */
class ReportService
{
    public function __construct(private Ledger $ledger, private TreasuryService $treasury)
    {
    }

    public function balances(): array
    {
        return [
            'secondary' => $this->ledger->balance(Ledger::AR_SECONDARY),
            'primary' => $this->ledger->balance(Ledger::AR_PRIMARY),
            'secondary_accounts' => Debt::where('bucket', DebtBucket::Secondary)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->distinct()->count('account_id'),
            'primary_accounts' => Debt::where('bucket', DebtBucket::Primary)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->distinct()->count('account_id'),
            'boxes' => MoneyAccount::where('is_active', true)->orderBy('kind')->get()
                ->map(fn (MoneyAccount $m) => ['name' => $m->name, 'kind' => $m->kind, 'balance' => $this->treasury->balance($m)])->all(),
            'cash' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->get()->sum(fn ($m) => $this->treasury->balance($m)),
            'electronic' => MoneyAccount::where('kind', MoneyAccountKind::Electronic)->get()->sum(fn ($m) => $this->treasury->balance($m)),
            'company' => MoneyAccount::where('kind', MoneyAccountKind::Company)->get()->sum(fn ($m) => $this->treasury->balance($m)),
        ];
    }

    public function period(CarbonImmutable $from, CarbonImmutable $to, ?int $userId = null): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $byUser = fn (Builder $q, string $column = 'created_by') => $userId ? $q->where($q->qualifyColumn($column), $userId) : $q;

        $payments = $byUser(Payment::query()->where('payments.status', DocumentStatus::Posted)->whereBetween('payments.received_at', $range));
        $activations = $byUser(Activation::query()->where('activations.status', DocumentStatus::Posted)->whereBetween('activations.created_at', $range));
        $debts = $byUser(Debt::query()->where('status', '<>', DebtStatus::Voided)->whereBetween('created_at', $range));
        $transfers = $byUser(DebtTransfer::query()->where('status', DocumentStatus::Posted)->whereBetween('performed_at', $range), 'performed_by');
        $expenses = $byUser(Expense::query()->where('expenses.status', DocumentStatus::Posted)->whereBetween('expenses.spent_at', $range));

        return [
            'collections' => [
                'total' => (int) (clone $payments)->sum('payments.amount'),
                'count' => (clone $payments)->count(),
                'by_method' => (clone $payments)->select('payments.method', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as n'))->groupBy('payments.method')->get()
                    ->map(fn ($r) => ['label' => $r->method->getLabel(), 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'by_type' => (clone $payments)->select('payments.payment_type', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as n'))->groupBy('payments.payment_type')->get()
                    ->map(fn ($r) => ['label' => $r->payment_type->getLabel(), 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'by_box' => (clone $payments)->join('money_accounts', 'money_accounts.id', '=', 'payments.money_account_id')
                    ->select('money_accounts.name as label', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as n'))->groupBy('money_accounts.name')->get()
                    ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'by_user' => (clone $payments)->join('users', 'users.id', '=', 'payments.created_by')
                    ->select('users.name as label', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as n'))->groupBy('users.name')->get()
                    ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total, 'count' => $r->n])->all(),
            ],
            'activations' => [
                'count' => (clone $activations)->count(),
                'value' => (int) (clone $activations)->sum('activations.final_price'),
                'discounts' => (int) (clone $activations)->sum('activations.discount_amount'),
                'by_plan' => (clone $activations)->join('service_plans', 'service_plans.id', '=', 'activations.plan_id')
                    ->select('service_plans.name_ar as plan', 'activations.kind', DB::raw('count(*) as n'), DB::raw('sum(activations.final_price) as total'))
                    ->groupBy('service_plans.name_ar', 'activations.kind', 'service_plans.sort_order')->orderBy('service_plans.sort_order')->get()
                    ->map(fn ($r) => ['label' => "{$r->plan} · {$r->kind->getLabel()}", 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'completed_by_payment' => $byUser(Activation::query()->where('completed_via', 'payment')->whereBetween('completed_at', $range))->count(),
                'completed_by_transfer' => $byUser(Activation::query()->where('completed_via', 'transfer')->whereBetween('completed_at', $range))->count(),
            ],
            'debts' => [
                'secondary' => (int) (clone $debts)->where('bucket', DebtBucket::Secondary)->sum('original_amount'),
                'primary' => (int) (clone $debts)->where('bucket', DebtBucket::Primary)->sum('original_amount'),
                'count' => (clone $debts)->count(),
            ],
            'transfers' => ['count' => (clone $transfers)->count(), 'total' => (int) (clone $transfers)->sum('amount')],
            'expenses' => [
                'total' => (int) (clone $expenses)->sum('expenses.amount'),
                'by_category' => (clone $expenses)->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
                    ->select('expense_categories.name_ar as label', DB::raw('sum(expenses.amount) as total'), DB::raw('count(*) as n'))->groupBy('expense_categories.name_ar')->get()
                    ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total, 'count' => $r->n])->all(),
            ],
            'settlements' => (int) CompanySettlement::where('status', DocumentStatus::Posted)->whereBetween('received_at', $range)->sum('amount'),
            'voids' => $byUser(AuditLog::query()->where('action', 'like', '%.voided')->whereBetween('occurred_at', $range), 'user_id')
                ->select('action', DB::raw('count(*) as n'))->groupBy('action')->pluck('n', 'action')->all(),
        ];
    }
}

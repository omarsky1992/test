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
use App\Models\DeviceSale;
use App\Models\FundTransfer;
use App\Models\DebtTransfer;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentLine;
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
            // Cash collected by employees and not yet handed over: still the company's money.
            'custody' => MoneyAccount::where('kind', MoneyAccountKind::Custody)->get()->sum(fn ($m) => $this->treasury->balance($m)),
            'custody_holders' => MoneyAccount::where('kind', MoneyAccountKind::Custody)->get()->filter(fn ($m) => $this->treasury->balance($m) !== 0)->count(),
            // Owed by employees: shown beside the balance, like the debts, not inside it.
            'advances' => $this->ledger->balance(Ledger::EMPLOYEE_ADVANCES),
            'advances_count' => \App\Models\EmployeeAdvance::where('balance', '>', 0)->count(),
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
                'by_method' => PaymentLine::query()
                    ->join('payments', 'payments.id', '=', 'payment_lines.payment_id')
                    ->join('payment_methods', 'payment_methods.id', '=', 'payment_lines.payment_method_id')
                    ->whereIn('payments.id', (clone $payments)->select('payments.id'))
                    ->select('payment_methods.name_ar as label', 'payment_methods.sort_order', DB::raw('sum(payment_lines.amount) as total'), DB::raw('count(*) as n'))
                    ->groupBy('payment_methods.name_ar', 'payment_methods.sort_order')->orderBy('payment_methods.sort_order')->get()
                    ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'by_type' => (clone $payments)->select('payments.payment_type', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as n'))->groupBy('payments.payment_type')->get()
                    ->map(fn ($r) => ['label' => $r->payment_type->getLabel(), 'total' => (int) $r->total, 'count' => $r->n])->all(),
                'by_box' => PaymentLine::query()
                    ->join('money_accounts', 'money_accounts.id', '=', 'payment_lines.money_account_id')
                    ->whereIn('payment_lines.payment_id', (clone $payments)->select('payments.id'))
                    ->select('money_accounts.name as label', DB::raw('sum(payment_lines.amount) as total'), DB::raw('count(*) as n'))->groupBy('money_accounts.name')->get()
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

    /**
     * Resolves the dashboard period filter to a [from, to] pair of Baghdad days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public static function periodRange(?array $filters): array
    {
        $today = CarbonImmutable::today();
        $period = $filters['period'] ?? 'today';

        return match ($period) {
            'week' => [$today->subDays(6), $today, 'آخر 7 أيام'],
            'month' => [$today->startOfMonth(), $today, 'هذا الشهر'],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'الشهر الماضي'],
            'custom' => [
                CarbonImmutable::parse($filters['from'] ?? $today),
                CarbonImmutable::parse($filters['to'] ?? $filters['from'] ?? $today),
                'فترة مخصصة',
            ],
            default => [$today, $today, 'اليوم'],
        };
    }

    /**
     * Money in, sales and purchases for a period. Secondary debts are reported separately and
     * never counted in the total balance.
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $company = MoneyAccount::where('kind', MoneyAccountKind::Company)->pluck('id');

        $collections = (int) Payment::where('status', DocumentStatus::Posted)->whereBetween('received_at', $range)->sum('amount');
        $receiptsCount = Payment::where('status', DocumentStatus::Posted)->whereBetween('received_at', $range)->count();
        $activationSales = (int) Activation::where('status', DocumentStatus::Posted)->whereBetween('created_at', $range)->sum('final_price');
        $activationsCount = Activation::where('status', DocumentStatus::Posted)->whereBetween('created_at', $range)->count();
        $deviceSales = (int) DeviceSale::where('status', DocumentStatus::Posted)->whereBetween('sold_at', $range)->sum('total_amount');
        $deviceProfit = (int) DeviceSale::where('status', DocumentStatus::Posted)->whereBetween('sold_at', $range)->sum(DB::raw('total_amount - coalesce(cost_amount, 0)'));
        $expenses = (int) Expense::where('status', DocumentStatus::Posted)->whereBetween('spent_at', $range)->sum('amount');
        $topUps = (int) FundTransfer::where('status', DocumentStatus::Posted)->whereIn('to_money_account_id', $company)->whereBetween('transferred_at', $range)->sum('amount');

        return [
            'collections' => $collections,
            'receipts_count' => $receiptsCount,
            'activation_sales' => $activationSales,
            'activations_count' => $activationsCount,
            'device_sales' => $deviceSales,
            'device_profit' => $deviceProfit,
            'sales' => $activationSales + $deviceSales,
            'expenses' => $expenses,
            'company_topups' => $topUps,
            'purchases' => $expenses + $topUps,
        ];
    }

    /**
     * Daily collections, sales and purchases for the chart.
     *
     * @return array{labels: array<int, string>, collections: array<int, int>, sales: array<int, int>, purchases: array<int, int>}
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->diffInDays($to) < 6) {
            $from = $to->subDays(13);
        }
        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = ['collections' => 0, 'sales' => 0, 'purchases' => 0];
        }
        $range = [$from->startOfDay(), $to->endOfDay()];
        $company = MoneyAccount::where('kind', MoneyAccountKind::Company)->pluck('id');
        $add = function (string $key, $rows) use (&$days) {
            foreach ($rows as $row) {
                if (isset($days[$row->day])) {
                    $days[$row->day][$key] += (int) $row->total;
                }
            }
        };
        $byDay = fn ($query, string $column, string $sum) => $query->whereBetween($column, $range)
            ->selectRaw("to_char({$column}, 'YYYY-MM-DD') as day, sum({$sum}) as total")->groupBy('day')->get();

        $add('collections', $byDay(Payment::where('status', DocumentStatus::Posted), 'received_at', 'amount'));
        $add('sales', $byDay(Activation::where('status', DocumentStatus::Posted), 'created_at', 'final_price'));
        $add('sales', $byDay(DeviceSale::where('status', DocumentStatus::Posted), 'sold_at', 'total_amount'));
        $add('purchases', $byDay(Expense::where('status', DocumentStatus::Posted), 'spent_at', 'amount'));
        $add('purchases', $byDay(FundTransfer::where('status', DocumentStatus::Posted)->whereIn('to_money_account_id', $company), 'transferred_at', 'amount'));

        return [
            'labels' => array_map(fn ($d) => CarbonImmutable::parse($d)->format('m/d'), array_keys($days)),
            'collections' => array_column($days, 'collections'),
            'sales' => array_column($days, 'sales'),
            'purchases' => array_column($days, 'purchases'),
        ];
    }

    /**
     * Money received per payment method (a split receipt counts in each of its methods).
     */
    public function byPaymentMethod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return PaymentLine::query()
            ->join('payments', 'payments.id', '=', 'payment_lines.payment_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'payment_lines.payment_method_id')
            ->where('payments.status', DocumentStatus::Posted)
            ->whereBetween('payments.received_at', [$from->startOfDay(), $to->endOfDay()])
            ->select('payment_methods.name_ar as label', 'payment_methods.category', 'payment_methods.sort_order', DB::raw('sum(payment_lines.amount) as total'), DB::raw('count(*) as n'))
            ->groupBy('payment_methods.name_ar', 'payment_methods.category', 'payment_methods.sort_order')
            ->orderBy('payment_methods.sort_order')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'category' => $r->category, 'total' => (int) $r->total, 'count' => $r->n])
            ->all();
    }
}

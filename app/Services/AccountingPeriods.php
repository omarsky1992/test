<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Models\Activation;
use App\Models\DeviceSale;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\MoneyAccount;
use App\Models\PeriodClosing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchases and sales by accounting period (any start and end, e.g. the 15th to the 14th). The same
 * definitions as the dashboard: purchases = expenses; sales = devices, materials and cards (not
 * internet activations); الربح والخسارة = sales − purchases. Closing a period only records its totals: nothing is deleted and no balance moves.
 */
class AccountingPeriods
{
    public function __construct(private Settings $settings, private Audit $audit) {}

    /** The exact moment the open period of purchases or sales starts (the last «تصفير», or the chosen day). */
    /**
     * Where the dashboard's running totals start: the last «تصفير» of this scope (or the start date
     * the admin chose); never reset yet, from the very first record.
     */
    public function lastReset(string $scope): CarbonImmutable
    {
        $last = PeriodClosing::where('scope', $scope)->max('ends_at');
        $chosen = $this->settings->get("periods.{$scope}_start");
        $candidates = array_filter([
            $last ? CarbonImmutable::parse($last) : null,
            filled($chosen) ? CarbonImmutable::parse($chosen)->startOfDay() : null,
        ]);

        return $candidates !== [] ? collect($candidates)->max() : CarbonImmutable::create(2000, 1, 1);
    }

    public function currentStart(string $scope): CarbonImmutable
    {
        $last = PeriodClosing::where('scope', $scope)->max('ends_at');
        $chosen = $this->settings->get("periods.{$scope}_start");
        $candidates = array_filter([
            $last ? CarbonImmutable::parse($last) : null,
            filled($chosen) ? CarbonImmutable::parse($chosen)->startOfDay() : null,
        ]);
        if ($candidates !== []) {
            return collect($candidates)->max();
        }
        $day = max(1, min(28, (int) $this->settings->get('periods.start_day')));
        $today = CarbonImmutable::today();

        return $today->day >= $day ? $today->setDay($day) : $today->subMonthNoOverflow()->setDay($day);
    }

    /**
     * Whole days (from the first day's start to the end of the last), or, $exact, from one moment
     * (included) to another (excluded) so periods that touch never count an operation twice.
     *
     * @return \Closure(Builder, string): Builder
     */
    private function within(CarbonImmutable $from, CarbonImmutable $to, bool $exact): \Closure
    {
        return $exact
            ? fn ($query, string $column) => $query->where($column, '>=', $from)->where($column, '<', $to)
            : fn ($query, string $column) => $query->where($column, '>=', $from->startOfDay())->where($column, '<=', $to->endOfDay());
    }

    /**
     * @return array{purchases: int, sales: int, difference: int, purchases_count: int, sales_count: int, breakdown: array<string, array{total: int, count: int}>}
     */
    public function totals(CarbonImmutable $from, CarbonImmutable $to, bool $exact = false): array
    {
        $in = $this->within($from, $to, $exact);
        $company = MoneyAccount::where('kind', MoneyAccountKind::Company)->pluck('id');
        $part = fn ($query, string $column, string $amount) => ['total' => (int) (clone $query)->sum($amount), 'count' => (clone $query)->count()];
        $breakdown = [
            'expenses' => $part($in(Expense::where('status', DocumentStatus::Posted), 'spent_at'), 'spent_at', 'amount'),
            'company_topups' => $part($in(FundTransfer::where('status', DocumentStatus::Posted)->whereIn('to_money_account_id', $company), 'transferred_at'), 'transferred_at', 'amount'),
            'activations' => $part($in(Activation::where('status', DocumentStatus::Posted), 'created_at'), 'created_at', 'final_price'),
            'device_sales' => $part($in(DeviceSale::where('status', DocumentStatus::Posted), 'sold_at'), 'sold_at', 'total_amount'),
        ];
        // Purchases = expenses; sales = devices, materials and cards. Activations and company top-ups
        // stay in the breakdown for information only.
        $purchases = $breakdown['expenses']['total'];
        $sales = $breakdown['device_sales']['total'];

        return [
            'purchases' => $purchases,
            'sales' => $sales,
            'difference' => $sales - $purchases,
            'purchases_count' => $breakdown['expenses']['count'],
            'sales_count' => $breakdown['device_sales']['count'],
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The operations of a period, newest first.
     *
     * @return array{purchases: Collection<int, array>, sales: Collection<int, array>}
     */
    public function operations(CarbonImmutable $from, CarbonImmutable $to, int $limit = 300, bool $exact = false): array
    {
        $in = $this->within($from, $to, $exact);
        $purchases = $in(Expense::with('category')->where('status', DocumentStatus::Posted), 'spent_at')->latest('spent_at')->limit($limit)->get()->toBase()
            ->map(fn (Expense $e) => ['at' => $e->spent_at, 'number' => $e->number, 'kind' => $e->category?->name_ar ?? 'مصروف', 'details' => $e->description, 'amount' => (int) $e->amount])
            ->values();
        $sales = $in(DeviceSale::with('itemType')->where('status', DocumentStatus::Posted), 'sold_at')->latest('sold_at')->limit($limit)->get()->toBase()
            ->map(fn (DeviceSale $s) => ['at' => $s->sold_at, 'number' => $s->number, 'kind' => $s->itemType?->name_ar ?? 'بيع', 'details' => $s->description, 'amount' => (int) $s->total_amount])
            ->values();

        return ['purchases' => $purchases, 'sales' => $sales];
    }

    /**
     * «تصفير»: closes the open period of purchases, of sales, or both. Without a day it ends now (what
     * comes after belongs to the new period); with a past day it ends at the end of that day.
     *
     * @param  array<int, string>  $scopes
     * @return Collection<int, PeriodClosing>
     */
    public function close(array $scopes, ?CarbonImmutable $endsOn = null, ?string $notes = null): Collection
    {
        $this->authorize();
        $scopes = array_values(array_intersect(['purchases', 'sales'], $scopes));
        if ($scopes === []) {
            throw new BusinessRuleException('اختر ما تريد تصفيره.');
        }
        if ($endsOn !== null && $endsOn->startOfDay()->greaterThan(CarbonImmutable::today())) {
            throw new BusinessRuleException('لا يمكن إغلاق فترة تنتهي بعد اليوم.');
        }
        $endsAt = $endsOn === null || $endsOn->isToday() ? CarbonImmutable::now() : $endsOn->addDay()->startOfDay();

        return DB::transaction(function () use ($scopes, $endsAt, $notes) {
            $batch = (string) Str::uuid();
            $closed = collect();
            foreach ($scopes as $scope) {
                $start = $this->currentStart($scope);
                if (! $endsAt->greaterThan($start)) {
                    throw new BusinessRuleException('نهاية الفترة قبل بدايتها ('.PeriodClosing::SCOPES[$scope].' تبدأ '.$start->setTimezone(config('app.timezone'))->format('Y/m/d H:i').').');
                }
                $t = $this->totals($start, $endsAt, exact: true);
                $keys = $scope === 'purchases' ? ['expenses'] : ['device_sales'];
                $closing = PeriodClosing::create([
                    'scope' => $scope, 'starts_at' => $start, 'ends_at' => $endsAt,
                    'starts_on' => $start->setTimezone(config('app.timezone'))->toDateString(),
                    'ends_on' => $endsAt->subSecond()->setTimezone(config('app.timezone'))->toDateString(),
                    'total' => $t[$scope], 'operations' => $t["{$scope}_count"],
                    'breakdown' => array_intersect_key($t['breakdown'], array_flip($keys)),
                    'batch' => count($scopes) > 1 ? $batch : null, 'notes' => filled($notes) ? trim($notes) : null,
                    'closed_by' => Auth::id(), 'closed_at' => now(),
                ]);
                $this->audit->log('period.closed', $closing, null, [
                    'scope' => $scope, 'from' => $start->toIso8601String(), 'to' => $endsAt->toIso8601String(), 'total' => $closing->total, 'operations' => $closing->operations,
                ], $closing->notes);
                $closed->push($closing);
            }

            return $closed;
        });
    }

    /** «فتح فترة جديدة»: the open period of purchases or sales starts on the chosen day (never inside a closed one). */
    public function openNew(string $scope, CarbonImmutable $startsOn): void
    {
        $this->authorize();
        if (! array_key_exists($scope, PeriodClosing::SCOPES)) {
            throw new BusinessRuleException('اختر المشتريات أو المبيعات.');
        }
        $last = PeriodClosing::where('scope', $scope)->max('ends_at');
        if ($last && $startsOn->startOfDay()->lessThan(CarbonImmutable::parse($last))) {
            throw new BusinessRuleException('الفترة الجديدة تبدأ بعد آخر فترة مغلقة ('.CarbonImmutable::parse($last)->setTimezone(config('app.timezone'))->format('Y/m/d H:i').').');
        }
        $old = $this->currentStart($scope)->toDateString();
        $this->settings->set("periods.{$scope}_start", $startsOn->toDateString());
        $this->audit->log('period.opened', 'periods', ['scope' => $scope, 'starts_on' => $old], ['scope' => $scope, 'starts_on' => $startsOn->toDateString()]);
    }

    private function authorize(): void
    {
        if (! Auth::user()?->can('periods.close')) {
            throw new AuthorizationException('ليست لديك صلاحية إغلاق الفترات.');
        }
    }
}

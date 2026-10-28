<?php

namespace App\Services;

use App\Enums\MoneyAccountKind;
use App\Filament\Widgets\CardBalances;
use App\Models\MoneyAccount;
use App\Models\WhatsappNumber;
use App\Support\Money;
use App\WhatsApp\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * «تقرير الدوّار»: the money that turns in the business, added up: primary debts, what is on the cards,
 * the employees' custody and advances, and «الربح والخسارة» (since the last «تصفير», as on the dashboard), plus the cash
 * and the company page balance when entered by hand. A report only: it records and moves nothing.
 */
class RotationReport
{
    public function __construct(private Ledger $ledger, private TreasuryService $treasury, private AccountingPeriods $periods, private Outbox $outbox, private Audit $audit) {}

    /**
     * $target: «مقدار الدوّار», what the total should be; the difference says شكد نقص أو زيادة.
     *
     * @return array{lines: array<int, array{label: string, amount: int}>, total: int, target: ?int, difference: ?int}
     */
    public function compute(?int $cash = null, ?int $pageBalance = null, ?int $target = null): array
    {
        $now = CarbonImmutable::now()->addSecond();
        $purchases = $this->periods->totals($this->periods->lastReset('purchases'), $now, exact: true)['purchases'];
        $sales = $this->periods->totals($this->periods->lastReset('sales'), $now, exact: true)['sales'];
        $lines = [
            ['label' => 'الديون الأولية', 'amount' => $this->ledger->balance(Ledger::AR_PRIMARY)],
            ['label' => 'رصيد البطاقات', 'amount' => CardBalances::balances()['total']],
            ['label' => 'عهد الموظفين', 'amount' => (int) MoneyAccount::where('kind', MoneyAccountKind::Custody)->get()->sum(fn (MoneyAccount $m) => $this->treasury->balance($m))],
            // What the employees still owe on their advances: money of the business out with them.
            ['label' => 'سلف الموظفين', 'amount' => $this->ledger->balance(Ledger::EMPLOYEE_ADVANCES)],
            ['label' => 'الربح والخسارة', 'amount' => $sales - $purchases],
        ];
        if ($cash !== null) {
            $lines[] = ['label' => 'الكاش', 'amount' => $cash];
        }
        if ($pageBalance !== null) {
            $lines[] = ['label' => 'رصيد الصفحة', 'amount' => $pageBalance];
        }

        $total = array_sum(array_column($lines, 'amount'));

        return ['lines' => $lines, 'total' => $total, 'target' => $target, 'difference' => $target !== null ? $total - $target : null];
    }

    /** «نقص 10,000» / «زيادة 20,000» / «مطابق», or null without a target. */
    public static function differenceLabel(array $report): ?string
    {
        $d = $report['difference'] ?? null;

        return match (true) {
            $d === null => null,
            $d < 0 => 'نقص '.Money::format(-$d),
            $d > 0 => 'زيادة '.Money::format($d),
            default => 'مطابق ✓',
        };
    }

    public function text(array $report): string
    {
        $rows = array_map(fn (array $l) => "{$l['label']}: ".Money::format($l['amount']), $report['lines']);

        return '📊 تقرير الدوّار – '.CarbonImmutable::now()->setTimezone(config('app.timezone'))->format('Y/m/d H:i')
            ."\n".implode("\n", $rows)."\n————\nالمجموع: ".Money::format($report['total'])
            .($report['target'] !== null ? "\nمقدار الدوّار: ".Money::format($report['target'])."\nالنتيجة: ".self::differenceLabel($report) : '');
    }

    /**
     * Sends the report to the chosen authorized numbers (none chosen = all active ones), through the outbox.
     *
     * @return int messages queued
     */
    public function send(array $report, ?array $numberIds = null): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $numberIds))));
        $numbers = WhatsappNumber::with('user')->where('is_active', true)->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get()
            ->filter(fn (WhatsappNumber $n) => $n->user?->is_active ?? true);
        $text = $this->text($report);
        $batch = (string) Str::uuid();
        $queued = 0;
        foreach ($numbers as $number) {
            $queued += $this->outbox->queue($number->phone, $text, 'alert_report', "report:rotation:{$batch}:{$number->id}") ? 1 : 0;
        }
        $this->audit->log('report.rotation_sent', 'reports', null, ['total' => $report['total'], 'target' => $report['target'], 'difference' => $report['difference'], 'lines' => $report['lines'], 'numbers' => $numbers->pluck('id')->all()]);

        return $queued;
    }
}

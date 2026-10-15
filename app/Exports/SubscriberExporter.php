<?php

namespace App\Exports;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Models\Subscriber;
use App\Support\SubscriberStatus;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * «تصدير المشتركين»: one row per account with its subscriber, plan, end date and open debts. No
 * passwords, no locations or photos.
 */
class SubscriberExporter
{
    public const HEADERS = ['رقم المشترك', 'الاسم', 'الهاتف', 'اليوزر', 'الفئة', 'ينتهي', 'دين أولي', 'دين ثانوي', 'الحالة'];

    /** @return int rows written */
    public function write(string $path): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('المشتركون');
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));
        $sheet->setColumnWidthForRange(20, 1, count(self::HEADERS));
        $writer->addRow(Row::fromValues(self::HEADERS));

        $open = fn (DebtBucket $bucket) => fn ($d) => $d->where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);
        $count = 0;
        Subscriber::query()->with(['accounts' => fn ($q) => $q->with('currentPlan')
            ->withSum(['debts as primary_due' => $open(DebtBucket::Primary)], 'balance')
            ->withSum(['debts as secondary_due' => $open(DebtBucket::Secondary)], 'balance')])
            ->orderBy('id')->chunk(500, function ($subscribers) use ($writer, &$count) {
                foreach ($subscribers as $subscriber) {
                    foreach ($subscriber->accounts->isEmpty() ? [null] : $subscriber->accounts as $account) {
                        $ends = $account ? SubscriberStatus::endsAt($account) : null;
                        $writer->addRow(Row::fromValues([
                            (string) $subscriber->code, $subscriber->full_name, (string) $subscriber->phone,
                            (string) $account?->username, (string) ($account?->currentPlan?->name_ar ?? $account?->external_plan),
                            $ends ? $ends->setTimezone(config('app.timezone'))->format('Y-m-d') : '',
                            (int) ($account?->primary_due ?? 0), (int) ($account?->secondary_due ?? 0),
                            $subscriber->status?->getLabel() ?? '',
                        ]));
                        $count++;
                    }
                }
            });
        $writer->close();

        return $count;
    }
}

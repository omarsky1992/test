<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\SecondarySale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «المبيعات الثانوية»: recorded and counted on their own. They post nothing to the ledger, so no box,
 * card, custody, debt, sale, purchase or profit figure changes; a mistake is voided, never deleted.
 * Their running total starts again from «تصفير المبيعات الثانوية» (setting secondary_sales.reset_at).
 */
class SecondarySaleService
{
    public function __construct(private Sequencer $sequencer, private Audit $audit, private Settings $settings) {}

    public function record(string $description, int $amount, ?int $subscriberId = null, ?string $buyerName = null, ?string $notes = null, ?string $idempotencyKey = null): SecondarySale
    {
        $this->authorize('secondary_sales.create');
        if ($idempotencyKey !== null && ($existing = SecondarySale::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $description = trim($description);
        if ($description === '') {
            throw new BusinessRuleException('اكتب ما بعته.');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($description, $amount, $subscriberId, $buyerName, $notes, $idempotencyKey) {
            $now = CarbonImmutable::now();
            $sale = SecondarySale::create([
                'number' => $this->sequencer->next('secondary_sale', $now),
                'description' => mb_substr($description, 0, 200),
                'amount' => $amount,
                'subscriber_id' => $subscriberId,
                'buyer_name' => filled($buyerName) ? mb_substr(trim($buyerName), 0, 120) : null,
                'sold_at' => $now,
                'status' => 'posted',
                'notes' => filled($notes) ? trim($notes) : null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => Auth::id(),
            ]);
            $this->audit->log('secondary_sale.created', $sale, null, ['number' => $sale->number, 'amount' => $amount, 'description' => $sale->description], subscriberId: $subscriberId);

            return $sale;
        });
    }

    public function void(SecondarySale $sale, string $reason): SecondarySale
    {
        $this->authorize('secondary_sales.void');

        return DB::transaction(function () use ($sale, $reason) {
            $sale = SecondarySale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'posted') {
                throw new BusinessRuleException('البيع ملغى مسبقاً.');
            }
            $sale->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)]);
            $this->audit->log('secondary_sale.voided', $sale, ['status' => 'posted'], ['status' => 'voided', 'amount' => $sale->amount], $reason, $sale->subscriber_id);

            return $sale;
        });
    }

    /** Where the running total starts: the last reset, or the first record when never reset. */
    public function since(): CarbonImmutable
    {
        $at = $this->settings->get('secondary_sales.reset_at');

        return filled($at) ? CarbonImmutable::parse($at) : CarbonImmutable::create(2000, 1, 1);
    }

    /** @return array{total: int, count: int} */
    public function totals(): array
    {
        $row = SecondarySale::where('status', 'posted')->where('sold_at', '>=', $this->since())
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as n')->first();

        return ['total' => (int) $row->total, 'count' => (int) $row->n];
    }

    /** «تصفير المبيعات الثانوية»: the total starts again from now. Nothing is deleted; the audit log keeps the total. */
    public function reset(?string $notes = null): array
    {
        $this->authorize('secondary_sales.reset');
        $totals = $this->totals();
        $now = CarbonImmutable::now();
        $since = $this->since()->toIso8601String();
        $this->settings->set('secondary_sales.reset_at', $now->toIso8601String());
        $this->audit->log('secondary_sales.reset', 'settings', ['since' => $since], ['reset_at' => $now->toIso8601String(), ...$totals], $notes);

        return $totals;
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()?->can($permission)) {
            throw new AuthorizationException('ليست لديك صلاحية لهذا الإجراء.');
        }
    }
}

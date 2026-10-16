<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\VoucherCard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «مخزون الكارتات»: adding cards (one or many codes at once), activating an available card exactly
 * once, and setting a card aside as void. Inventory only: no money and no subscriber involved.
 */
class VoucherStock
{
    public function __construct(private Audit $audit) {}

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]+/u', '', strtr(trim($code), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])));
    }

    /**
     * Adds every code given (one per line); codes already in stock or repeated in the list are
     * skipped and reported, never duplicated.
     *
     * @param  array<int, string>  $codes
     * @return array{added: int, duplicates: array<int, string>, rejected: array<int, string>}
     */
    public function add(string $type, int $denomination, array $codes, ?string $notes = null): array
    {
        $this->authorize('stock.add');
        if (! array_key_exists($type, VoucherCard::TYPES)) {
            throw new BusinessRuleException('اختر نوع الكارت.');
        }
        if ($denomination <= 0) {
            throw new BusinessRuleException('الفئة يجب أن تكون أكبر من صفر.');
        }
        $codes = array_values(array_filter(array_map([self::class, 'normalize'], $codes), fn ($c) => $c !== ''));
        if ($codes === []) {
            throw new BusinessRuleException('اكتب رقم كارت واحد على الأقل.');
        }
        // Codes too short or too long are rejected one by one; the rest are still added.
        $rejected = array_values(array_filter($codes, fn ($c) => mb_strlen($c) > 100 || mb_strlen($c) < 4));
        $codes = array_values(array_diff($codes, $rejected));
        $unique = array_values(array_unique($codes));
        $existing = VoucherCard::whereIn('code', $unique)->pluck('code')->all();
        $duplicates = array_values(array_unique(array_merge($existing, array_diff_assoc($codes, array_unique($codes)))));
        $new = array_values(array_diff($unique, $existing));

        DB::transaction(function () use ($new, $type, $denomination, $notes) {
            $now = now();
            foreach (array_chunk($new, 500) as $chunk) {
                // ON CONFLICT: two people adding the same code at once still store it only once.
                DB::table('voucher_cards')->insertOrIgnore(array_map(fn ($code) => [
                    'type' => $type, 'denomination' => $denomination, 'code' => $code, 'status' => 'available',
                    'notes' => filled($notes) ? trim($notes) : null, 'added_by' => Auth::id(), 'created_at' => $now,
                ], $chunk));
            }
            if ($new !== []) {
                $this->audit->log('stock.added', 'voucher_cards', null, [
                    'type' => $type, 'denomination' => $denomination, 'count' => count($new),
                ]);
            }
        });

        return ['added' => count($new), 'duplicates' => $duplicates, 'rejected' => $rejected];
    }

    /** Available → activated, once: a second activation (another tab, a double click) is refused. */
    public function activate(VoucherCard $card): VoucherCard
    {
        $this->authorize('stock.activate');

        return DB::transaction(function () use ($card) {
            $updated = VoucherCard::whereKey($card->id)->where('status', 'available')
                ->update(['status' => 'activated', 'activated_at' => now(), 'activated_by' => Auth::id()]);
            if ($updated !== 1) {
                throw new BusinessRuleException('الكارت غير متوفر (مفعّل أو ملغى مسبقاً).');
            }
            $card->refresh();
            $this->audit->log('stock.activated', $card, ['status' => 'available'], ['status' => 'activated', 'type' => $card->type, 'denomination' => $card->denomination]);

            return $card;
        });
    }

    /**
     * «إنشاء»: takes ONE available card of exactly this type and denomination and marks it used, in one
     * transaction. Concurrent clicks never get the same card (row lock, SKIP LOCKED); the same click
     * ($key) repeated returns the card it already took. Never falls back to another category.
     */
    public function dispense(string $type, int $denomination, ?string $key = null): VoucherCard
    {
        $this->authorize('stock.dispense');
        if ($key !== null && ($taken = VoucherCard::where('dispense_key', $key)->first())) {
            return $taken;
        }

        return DB::transaction(function () use ($type, $denomination, $key) {
            $card = VoucherCard::where('type', $type)->where('denomination', $denomination)->where('status', 'available')
                ->orderBy('id')->lock('for update skip locked')->first();
            if (! $card) {
                throw new BusinessRuleException('نفدت الكروت من هذه الفئة');
            }
            $card->update(['status' => 'activated', 'activated_at' => now(), 'activated_by' => Auth::id(), 'dispense_key' => $key]);
            $this->audit->log('stock.dispensed', $card, ['status' => 'available'], ['status' => 'activated', 'type' => $type, 'denomination' => $denomination]);

            return $card;
        });
    }

    /**
     * The categories that exist (every type and denomination the admin added), with how many are left.
     *
     * @return Collection<int, object{type: string, denomination: int, total: int, available: int, activated: int, voided: int}>
     */
    public function categories(): Collection
    {
        return $this->summary();
    }

    public function void(VoucherCard $card, string $reason): VoucherCard
    {
        $this->authorize('stock.void');
        if (trim($reason) === '') {
            throw new BusinessRuleException('اكتب السبب.');
        }

        return DB::transaction(function () use ($card, $reason) {
            $updated = VoucherCard::whereKey($card->id)->where('status', 'available')
                ->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)]);
            if ($updated !== 1) {
                throw new BusinessRuleException('يُلغى الكارت المتوفر فقط.');
            }
            $card->refresh();
            $this->audit->log('stock.voided', $card, ['status' => 'available'], ['status' => 'void'], $reason);

            return $card;
        });
    }

    /** Totals by type and denomination: all, available, activated, void. */
    public function summary(): Collection
    {
        return VoucherCard::query()
            ->selectRaw("type, denomination, count(*) as total, count(*) filter (where status = 'available') as available,
                count(*) filter (where status = 'activated') as activated, count(*) filter (where status = 'void') as voided")
            ->groupBy('type', 'denomination')->orderBy('type')->orderBy('denomination')->get();
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()?->can($permission)) {
            throw new AuthorizationException('ليست لديك صلاحية لهذا الإجراء.');
        }
    }
}

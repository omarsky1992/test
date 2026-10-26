<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\AccountRenewal;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The company wallet log (admin.ftth.iq/wallet/transactions), read by the browser sync: each
 * subscription renewal there says how it was paid («نوع الدفع»: Card or Wallet). A line is matched
 * to a renewal of this system by the subscription (ID, else device name) and the new end date, and
 * only labels it; it never creates, changes or removes a debt or any money.
 */
class WalletTransactions
{
    public function __construct(private Audit $audit) {}

    /**
     * @param  array<int, mixed>  $items  the log lines as the panel returned them
     * @return array{read: int, matched: int, card: int, wallet: int}
     */
    public function apply(array $items): array
    {
        $result = ['read' => 0, 'matched' => 0, 'card' => 0, 'wallet' => 0];
        $lines = [];
        foreach ($items as $item) {
            if (is_array($item) && ($line = self::parse($item)) !== null) {
                $lines[] = $line;
            }
        }
        $result['read'] = count($lines);
        if ($lines === []) {
            return $result;
        }

        $values = array_values(array_unique(array_merge(...array_column($lines, 'values'))));
        $accounts = Account::query()
            ->where(fn ($q) => $q->whereIn('external_subscription_id', $values)->orWhereIn(DB::raw('upper(username)'), array_map('mb_strtoupper', $values)))
            ->get(['id', 'subscriber_id', 'external_subscription_id', 'username']);
        $bySubscription = $accounts->filter(fn ($a) => $a->external_subscription_id)->keyBy(fn ($a) => (string) $a->external_subscription_id);
        $byUsername = $accounts->filter(fn ($a) => $a->username)->keyBy(fn ($a) => mb_strtoupper($a->username));

        foreach ($lines as $line) {
            $account = null;
            foreach ($line['values'] as $v) {
                $account ??= $bySubscription->get($v);
            }
            foreach ($line['values'] as $v) {
                $account ??= $byUsername->get(mb_strtoupper($v));
            }
            if ($account === null) {
                continue;
            }
            $renewal = $this->match($account->id, $line['ends']);
            if ($renewal === null) {
                continue;
            }
            $renewal->update(['payment_source' => $line['source'], 'company_txn_id' => $line['id'], 'company_amount' => $line['amount']]);
            $this->audit->log('renewal.payment_source', $renewal, null, [
                'payment_source' => $line['source'], 'company_txn' => $line['id'], 'amount' => $line['amount'],
            ], 'نوع الدفع من سجل محفظة موقع الشركة', $renewal->subscriber_id, 'sync');
            $result['matched']++;
            $result[$line['source']]++;
        }

        return $result;
    }

    /** The renewal of this account whose new end date is the line's (a day either way), not yet labelled. */
    private function match(int $accountId, CarbonImmutable $ends): ?AccountRenewal
    {
        $tz = config('app.timezone');
        $day = $ends->setTimezone($tz)->startOfDay();

        return AccountRenewal::where('account_id', $accountId)->whereNull('payment_source')
            ->whereBetween('new_ends_at', [$day->subDay(), $day->addDays(2)])
            ->get()
            ->sortBy(fn (AccountRenewal $r) => abs($r->new_ends_at->setTimezone($tz)->startOfDay()->diffInDays($day, false)))
            ->first();
    }

    /**
     * One log line, whatever its exact field names: the payment type (a value «Card» or «Wallet»),
     * the new end date (a date under a key about the end/expiry), every short value (to find the
     * subscription), its ID and amount. Value-added services (VAS: IPTV, parental control) are skipped.
     *
     * @return array{source: string, ends: CarbonImmutable, values: array<int, string>, id: ?string, amount: ?int}|null
     */
    public static function parse(array $item): ?array
    {
        $flat = self::flatten($item);
        $source = null;
        $ends = null;
        $values = [];
        $types = [];
        foreach ($flat as $key => $value) {
            $text = trim((string) $value);
            $lower = mb_strtolower($text);
            if (in_array($lower, ['card', 'wallet'], true) && ($source === null || str_contains($key, 'pay'))) {
                $source = $lower;
            }
            if (in_array($lower, ['vas', 'base_plan', 'baseplan', 'base'], true)) {
                $types[] = $lower;
            }
            if ($ends === null && preg_match('/end|expir/', $key) && ($date = self::date($text)) !== null) {
                $ends = $date;
            }
            if ($text !== '' && mb_strlen($text) <= 40) {
                $values[] = $text;
            }
        }
        if ($source === null || $ends === null || (in_array('vas', $types, true) && ! array_intersect($types, ['base_plan', 'baseplan', 'base']))) {
            return null;
        }
        // When the line names its subscription (subscription.id, deviceUsername, as admin.ftth.iq's
        // /api/transactions does), only those find the account: the customer's or partner's ID never does.
        $own = array_filter([$flat['subscription.id'] ?? null, $flat['deviceusername'] ?? null], fn ($v) => $v !== null && trim((string) $v) !== '');
        if ($own !== []) {
            $values = array_map(fn ($v) => trim((string) $v), $own);
        }
        $id = $flat['id'] ?? $flat['transactionid'] ?? null;
        $amount = null;
        foreach ($flat as $key => $value) {
            if (str_contains($key, 'amount') && is_numeric($value) && (int) abs((float) $value) > 0) {
                $amount = (int) abs((float) $value);
                break;
            }
        }

        return ['source' => $source, 'ends' => $ends, 'values' => array_values(array_unique($values)), 'id' => $id !== null ? mb_substr((string) $id, 0, 60) : null, 'amount' => $amount];
    }

    /** @return array<string, scalar> lower-case dotted keys */
    private static function flatten(array $item, string $prefix = ''): array
    {
        $flat = [];
        foreach ($item as $key => $value) {
            $path = $prefix.mb_strtolower((string) $key);
            if (is_array($value)) {
                $flat += self::flatten($value, $path.'.');
            } elseif (is_scalar($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private static function date(string $text): ?CarbonImmutable
    {
        $tz = config('app.timezone');
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $m)) {
            return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, $tz);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) {
            return rescue(fn () => CarbonImmutable::parse($text, $tz), null, false);
        }

        return null;
    }
}

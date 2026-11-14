<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\SyncRun;
use App\Services\Audit;
use App\Services\RenewalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The company wallet log (admin.ftth.iq/wallet/transactions), read by the browser sync: each
 * subscription renewal there says how it was paid: by card, from the agent's wallet
 * (`walletOwnerType` Partner) or from the subscriber's own (Customer). A line is matched to a renewal
 * of this system by the subscription (ID, else device name) and the new end date (a renewal the site
 * records with a «تغيير التاريخ» in the same moment ends where that change ends), else by when it
 * happened, and only labels it. For an account a sync just saw for the first time already active
 * (`fresh_accounts`) a renewal line of the last few days is that activation: it is recorded like a
 * sync's (`RenewalService::record`, marked new). Never a debt or any money by itself.
 */
class WalletTransactions
{
    public function __construct(private Audit $audit) {}

    /**
     * @param  array<int, mixed>  $items  the log lines as the panel returned them
     * @param  array<int, int>  $fresh  accounts first seen active in this run whose start is unknown
     * @return array{read: int, matched: int, card: int, wallet: int, wallet_partner: int, wallet_customer: int, new: int}
     */
    public function apply(array $items, ?SyncRun $run = null, array $fresh = []): array
    {
        $result = ['read' => 0, 'matched' => 0, 'card' => 0, 'wallet' => 0, 'wallet_partner' => 0, 'wallet_customer' => 0, 'new' => 0];
        $lines = [];
        $changes = [];
        foreach ($items as $item) {
            if (is_array($item) && ($line = self::parse($item)) !== null) {
                if ($line['source'] === null) {
                    $changes[] = $line;
                } else {
                    $lines[] = $line;
                }
            }
        }
        $result['read'] = count($lines);
        if ($lines === []) {
            return $result;
        }
        // The site may record one renewal as a renewal plus a date change in the same moment
        // (e.g. 11/11 → 05/12, then 05/12 → 11/12): the subscription really ends where the change ends.
        foreach ($lines as &$line) {
            foreach ($changes as $change) {
                if ($line['at'] !== null && $change['at'] !== null && abs($line['at']->getTimestamp() - $change['at']->getTimestamp()) <= 120
                    && array_intersect($line['values'], $change['values']) && $change['ends']->greaterThan($line['ends'])) {
                    $line['ends'] = $change['ends'];
                }
            }
        }
        unset($line);

        $values = array_values(array_unique(array_merge(...array_column($lines, 'values'))));
        $accounts = Account::query()
            ->where(fn ($q) => $q->whereIn('external_subscription_id', $values)->orWhereIn(DB::raw('upper(username)'), array_map('mb_strtoupper', $values)))
            ->get(['id', 'subscriber_id', 'external_subscription_id', 'username', 'external_ends_at', 'company_days_left', 'external_plan', 'current_plan_id', 'branch_id']);
        $bySubscription = $accounts->filter(fn ($a) => $a->external_subscription_id)->keyBy(fn ($a) => (string) $a->external_subscription_id);
        $byUsername = $accounts->filter(fn ($a) => $a->username)->keyBy(fn ($a) => mb_strtoupper($a->username));
        $fresh = array_flip(array_map('intval', $fresh));

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
            if (isset($fresh[$account->id]) && $this->newActivation($account, $line, $run)) {
                unset($fresh[$account->id]);
                $result['new']++;
            }
            $renewal = $this->match($account->id, $line['ends'], $line['at']);
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

    /**
     * A first-seen account's renewal line of the last few days whose end is the account's: that is
     * its activation, recorded as a sync would (marked new).
     */
    private function newActivation(Account $account, array $line, ?SyncRun $run): bool
    {
        $now = CarbonImmutable::now();
        if ($line['at'] === null || $line['at']->lessThan($now->subDays(CompanySync::NEW_ACTIVATION_DAYS))
            || $account->external_ends_at === null || (int) $account->company_days_left <= 0
            || abs($account->external_ends_at->getTimestamp() - $line['ends']->getTimestamp()) > 2 * 86400) {
            return false;
        }
        $renewal = app(RenewalService::class)->record(
            $account, 0, (int) $account->company_days_left, null, $account->external_ends_at, $account->external_plan, $run, $now,
            longestDays: RenewalService::longestDays($account->external_ends_at, $line['at']), isNew: true,
        );

        return $renewal->wasRecentlyCreated;
    }

    /**
     * The renewal of this account, not yet labelled, whose new end date is the line's (a day either
     * way); else the full activation detected within a few days after the line.
     */
    private function match(int $accountId, CarbonImmutable $ends, ?CarbonImmutable $at): ?AccountRenewal
    {
        $tz = config('app.timezone');
        $day = $ends->setTimezone($tz)->startOfDay();

        $byEnd = AccountRenewal::where('account_id', $accountId)->whereNull('payment_source')
            ->whereBetween('new_ends_at', [$day->subDay(), $day->addDays(2)])
            ->get()
            ->sortBy(fn (AccountRenewal $r) => abs($r->new_ends_at->setTimezone($tz)->startOfDay()->diffInDays($day, false)))
            ->first();
        if ($byEnd !== null || $at === null) {
            return $byEnd;
        }

        return AccountRenewal::where('account_id', $accountId)->whereNull('payment_source')->where('status', 'activated')
            ->whereBetween('detected_at', [$at->subDay(), $at->addDays(CompanySync::NEW_ACTIVATION_DAYS)])
            ->get()
            ->sortBy(fn (AccountRenewal $r) => abs($r->detected_at->getTimestamp() - $at->getTimestamp()))
            ->first();
    }

    /**
     * One log line, whatever its exact field names: the payment type (a value «Card» or «Wallet»),
     * the new end date (a date under a key about the end/expiry), every short value (to find the
     * subscription), its ID and amount. Value-added services (VAS: IPTV, parental control) are skipped.
     *
     * A date change («تغيير التاريخ») comes back with no source, only to tell where a renewal really ends.
     *
     * @return array{source: ?string, ends: CarbonImmutable, at: ?CarbonImmutable, values: array<int, string>, id: ?string, amount: ?int}|null
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
        $change = str_contains(mb_strtolower((string) ($flat['type'] ?? '')), 'change');
        // Paid from a wallet: the agent's (Partner) or the subscriber's own (Customer).
        $owner = mb_strtolower(trim((string) ($flat['walletownertype.displayvalue'] ?? $flat['walletownertype'] ?? '')));
        if ($source === 'wallet' && in_array($owner, ['partner', 'customer'], true)) {
            $source = 'wallet_'.$owner;
        }
        if ($change && $source === null && $ends !== null && ! in_array('vas', $types, true)) {
            // A date change: no payment, kept only to tell where a renewal of the same moment ends.
        } elseif ($source === null || $ends === null || (in_array('vas', $types, true) && ! array_intersect($types, ['base_plan', 'baseplan', 'base']))) {
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

        $when = $flat['occuredat'] ?? $flat['occurredat'] ?? $flat['createdat'] ?? null;
        $at = is_string($when) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $when) ? rescue(fn () => CarbonImmutable::parse($when), null, false) : null;

        return ['source' => $source, 'ends' => $ends, 'at' => $at, 'values' => array_values(array_unique($values)), 'id' => $id !== null ? mb_substr((string) $id, 0, 60) : null, 'amount' => $amount];
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

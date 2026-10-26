<?php

namespace App\Sync;

use App\Models\Account;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «القدرة المستلمة»: the browser sync reads each running account's ONT from the company site
 * (`ont?username=…`, GET) and the last reading is kept on the account. Only a reading: it never
 * changes a subscription, a debt or any money.
 */
class OntReadings
{
    /** The running accounts whose reading is oldest (never read first), up to $limit device names. */
    public static function needs(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return SubscriberStatus::apply(SubscriberStatus::base(), 'active')
            ->whereNotNull('accounts.username')->where('accounts.username', '<>', '')
            ->orderByRaw('accounts.rx_read_at asc nulls first')->orderBy('accounts.id')
            ->limit($limit)->pluck('accounts.username')->all();
    }

    /**
     * @param  array<string, mixed>  $readings  device name => what the panel returned (its `model`, or the whole body)
     * @return int accounts updated
     */
    public function apply(array $readings, ?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $parsed = [];
        foreach ($readings as $username => $body) {
            if (is_string($username) && $username !== '' && is_array($body) && ($r = self::parse($body)) !== null) {
                $parsed[mb_strtoupper($username)] = $r;
            }
        }
        if ($parsed === []) {
            return 0;
        }
        $updated = 0;
        $accounts = Account::whereIn(DB::raw('upper(username)'), array_keys($parsed))->get(['id', 'username']);
        foreach ($accounts as $account) {
            $r = $parsed[mb_strtoupper($account->username)];
            $updated += Account::whereKey($account->id)->update(['rx_power' => $r['rx'], 'ont_status' => $r['status'], 'rx_read_at' => $at]);
        }

        return $updated;
    }

    /** @return array{rx: ?float, status: ?string}|null */
    public static function parse(array $body): ?array
    {
        $model = is_array($body['model'] ?? null) ? $body['model'] : $body;
        $rx = $model['rxPower'] ?? $model['rx_power'] ?? $model['rxpower'] ?? null;
        $rx = is_numeric($rx) && (float) $rx > -100 && (float) $rx < 10 ? round((float) $rx, 2) : null;
        $status = $model['status'] ?? null;
        $status = is_array($status) ? ($status['displayValue'] ?? $status['name'] ?? null) : $status;
        $status = is_scalar($status) && trim((string) $status) !== '' ? mb_substr(trim((string) $status), 0, 30) : null;

        return $rx === null && $status === null ? null : ['rx' => $rx, 'status' => $status];
    }
}

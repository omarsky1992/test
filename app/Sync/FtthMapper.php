<?php

namespace App\Sync;

/**
 * Turns the company panel's own JSON (as returned to its pages) into sync records. Used both
 * when the server reads the panel and when the browser button sends what the panel returned.
 */
class FtthMapper
{
    /**
     * @param  iterable<int, array>  $customers  items of /api/customers
     * @return array<string, array{name: ?string, phone: ?string}>
     */
    public static function customerIndex(iterable $customers): array
    {
        $index = [];
        foreach ($customers as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = self::text($c['id'] ?? $c['self']['id'] ?? null);
            if ($id !== null) {
                $index[$id] = ['name' => self::text($c['displayValue'] ?? $c['name'] ?? null), 'phone' => self::text($c['primaryPhone'] ?? null)];
            }
        }

        return $index;
    }

    public static function fromSubscription(array $item, array $customers): array
    {
        $customer = $item['customer'] ?? [];
        $customerId = self::text(is_array($customer) ? ($customer['id'] ?? $customer['self']['id'] ?? null) : $customer);
        $device = is_array($item['deviceDetails'] ?? null) ? $item['deviceDetails'] : [];

        return [
            'customer_id' => $customerId,
            'name' => self::text(is_array($customer) ? ($customer['displayValue'] ?? null) : null) ?? ($customers[$customerId]['name'] ?? null),
            'phone' => $customers[$customerId]['phone'] ?? null,
            'plan' => self::planName($item),
            'status' => self::text($item['status'] ?? null),
            'ends_at' => self::text($item['expires'] ?? $item['expiresAt'] ?? null),
            'zone' => self::text($item['zone'] ?? null),
            'gps' => null,
            'username' => self::text($item['username'] ?? $device['username'] ?? null),
            'serial' => self::text($device['serial'] ?? null),
            'fat' => self::text($device['fat'] ?? null),
            'port' => self::text($device['fat']['portNumber'] ?? null),
            'subscription_id' => self::text($item['self']['id'] ?? $item['id'] ?? null),
        ];
    }

    public static function mergeDetails(array $record, array $details): array
    {
        $customer = is_array($details['customer'] ?? null) ? $details['customer'] : [];
        $details['subscriptions'] = is_array($details['subscriptions'] ?? null) ? $details['subscriptions'] : [];
        $record['phone'] ??= self::text($customer['primaryContact']['mobile'] ?? null);
        $record['name'] ??= self::text($customer['self']['displayValue'] ?? $customer['displayValue'] ?? null);
        foreach (is_array($customer['addresses'] ?? null) ? $customer['addresses'] : [] as $address) {
            if (! is_array($address)) {
                continue;
            }
            $lat = $address['gpsCoordinate']['latitude'] ?? null;
            $lng = $address['gpsCoordinate']['longitude'] ?? null;
            if ($lat !== null && $lng !== null && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
                $record['gps'] = "{$lat},{$lng}";
                break;
            }
        }

        foreach ($details['subscriptions'] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $device = $s['deviceDetails'] ?? [];
            $sameId = $record['subscription_id'] !== null && self::text($s['self']['id'] ?? $s['id'] ?? null) === $record['subscription_id'];
            $sameUser = $record['username'] !== null && strcasecmp((string) self::text($device['username'] ?? null), $record['username']) === 0;
            if ($sameId || $sameUser) {
                $record['username'] ??= self::text($device['username'] ?? null);
                $record['serial'] = self::text($device['serial'] ?? null) ?? $record['serial'];
                $record['fat'] = self::text($device['fat'] ?? null) ?? $record['fat'];
                $record['port'] = self::text($device['fat']['portNumber'] ?? null) ?? $record['port'];
                $record['zone'] ??= self::text($device['fdt'] ?? null);
                break;
            }
        }

        return $record;
    }

    public static function planName(array $item): ?string
    {
        foreach (is_array($item['services'] ?? null) ? $item['services'] : [] as $service) {
            if (! is_array($service)) {
                continue;
            }
            $type = self::text($service['type'] ?? null);
            if ($type !== null && strcasecmp($type, 'Base') === 0) {
                return self::text($service['value'] ?? $service);
            }
        }

        return self::text($item['bundle'] ?? null) ?? self::text($item['bundleId'] ?? null);
    }

    /**
     * The panel returns either plain values or {id, displayValue} objects.
     */
    public static function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['displayValue'] ?? $value['value'] ?? $value['name'] ?? $value['title'] ?? $value['id'] ?? null;
            if (is_array($value)) {
                return null;
            }
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

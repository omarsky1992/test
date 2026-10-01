<?php

namespace App\Sync;

/**
 * A source of the company's current subscriber list. Every record has the same keys:
 * customer_id, name, phone, plan, status, ends_at, zone, gps, username, serial, fat, port,
 * subscription_id (strings, or null when the source doesn't have the value).
 */
interface CompanyClient
{
    /**
     * @param  array{needs_details?: callable(array): bool, detail_limit?: int, limit?: int}  $options
     * @return iterable<int, array<string, ?string>>
     */
    public function records(array $options = []): iterable;
}

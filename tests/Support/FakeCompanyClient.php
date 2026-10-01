<?php

namespace Tests\Support;

use App\Sync\CompanyClient;

/**
 * Stands in for the company site: returns whatever records the test sets.
 */
class FakeCompanyClient implements CompanyClient
{
    /** @var array<int, array<string, ?string>> */
    public array $records = [];

    public function set(array ...$records): self
    {
        $this->records = array_map(fn (array $r) => self::record($r), $records);

        return $this;
    }

    public static function record(array $values): array
    {
        return [
            'customer_id' => null, 'name' => null, 'phone' => null, 'plan' => null, 'status' => null, 'ends_at' => null,
            'zone' => null, 'gps' => null, 'username' => null, 'serial' => null, 'fat' => null, 'port' => null,
            'subscription_id' => null, ...$values,
        ];
    }

    public function records(array $options = []): iterable
    {
        return $this->records;
    }
}

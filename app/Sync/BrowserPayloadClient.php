<?php

namespace App\Sync;

/**
 * What the browser button read from the company panel (with the user's own session, from
 * inside Iraq), handed to the sync as if it came from the panel directly.
 */
class BrowserPayloadClient implements CompanyClient
{
    /**
     * @param  array<int, array>  $customers  items of /api/customers
     * @param  array<int, array>  $subscriptions  items of /api/subscriptions
     * @param  array<string, array{customer?: array, subscriptions?: array}>  $details  per customer ID
     */
    public function __construct(private array $customers, private array $subscriptions, private array $details = [])
    {
    }

    public function records(array $options = []): iterable
    {
        $index = FtthMapper::customerIndex($this->customers);
        foreach ($this->subscriptions as $item) {
            if (! is_array($item)) {
                continue;
            }
            $record = FtthMapper::fromSubscription($item, $index);
            if ($record['customer_id'] !== null && isset($this->details[$record['customer_id']])) {
                $record = FtthMapper::mergeDetails($record, $this->details[$record['customer_id']]);
            }

            yield $record;
        }
    }
}

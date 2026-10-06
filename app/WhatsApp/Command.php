<?php

namespace App\WhatsApp;

/**
 * What a message asks for, as understood by an interpreter.
 */
final class Command
{
    public const INTENTS = ['activate', 'payment', 'purchase', 'sale', 'add_debt', 'transfer', 'void_debt', 'query', 'clarify', 'unknown'];

    public const BUCKETS = ['primary', 'secondary'];

    public const QUERIES = [
        'secondary_debts', 'primary_debts', 'late', 'activated_today', 'sales_today',
        'purchases_today', 'custody', 'advances', 'subscriber_debt', 'must_activate',
    ];

    /**
     * @param  array<int, array{description: string, quantity: ?int, unit_price: ?int, total: ?int}>  $items
     */
    public function __construct(
        public string $intent,
        public ?string $subscriber = null,
        public ?int $days = null,
        public ?int $amount = null,
        public array $items = [],
        public ?string $query = null,
        public ?string $question = null,
        public ?string $bucket = null,
        public bool $onCredit = false,
    ) {
        if ($this->bucket !== null && ! in_array($this->bucket, self::BUCKETS, true)) {
            $this->bucket = null;
        }
        if (! in_array($this->intent, self::INTENTS, true)) {
            $this->intent = 'unknown';
        }
        if ($this->query !== null && ! in_array($this->query, self::QUERIES, true)) {
            $this->query = null;
        }
    }

    public static function fromArray(array $data): self
    {
        $int = fn ($v) => is_numeric($v) ? (int) $v : null;

        return new self(
            intent: (string) ($data['intent'] ?? 'unknown'),
            subscriber: filled($data['subscriber'] ?? null) ? trim((string) $data['subscriber']) : null,
            days: $int($data['days'] ?? null),
            amount: $int($data['amount'] ?? null),
            items: array_values(array_map(fn ($i) => [
                'description' => trim((string) ($i['description'] ?? '')),
                'quantity' => $int($i['quantity'] ?? null),
                'unit_price' => $int($i['unit_price'] ?? null),
                'total' => $int($i['total'] ?? null),
            ], array_filter((array) ($data['items'] ?? []), 'is_array'))),
            query: filled($data['query'] ?? null) ? (string) $data['query'] : null,
            question: filled($data['question'] ?? null) ? (string) $data['question'] : null,
            bucket: filled($data['bucket'] ?? null) ? (string) $data['bucket'] : null,
            onCredit: (bool) ($data['on_credit'] ?? false),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'intent' => $this->intent,
            'subscriber' => $this->subscriber,
            'days' => $this->days,
            'amount' => $this->amount,
            'items' => $this->items ?: null,
            'query' => $this->query,
            'question' => $this->question,
            'bucket' => $this->bucket,
            'on_credit' => $this->onCredit ?: null,
        ], fn ($v) => $v !== null);
    }
}

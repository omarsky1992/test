<?php

namespace App\Imports;

/**
 * The result of analysing an import file: counts for the preview, one outcome per file row,
 * and the matched groups the importer applies after confirmation.
 */
class ImportPlan
{
    /** @var array<int, array> */
    public array $groups = [];

    /** @var array<string, int> usernames already taken by an earlier row of the file => its line */
    public array $claimedUsernames = [];

    /** @var array<string, string> serial => username of the first row that used it */
    public array $claimedSerials = [];

    /** @var array<int, array{line: int, name: string, phone: string, username: string, status: string, messages: array<int, string>}> */
    public array $rows = [];

    public array $stats = [
        'rows_total' => 0,
        'rows_error' => 0,
        'rows_merged' => 0,
        'rows_with_warnings' => 0,
        'subscribers_create' => 0,
        'subscribers_update' => 0,
        'subscribers_same' => 0,
        'accounts_create' => 0,
        'accounts_update' => 0,
        'accounts_same' => 0,
    ];

    public function __construct(public string $mode, public bool $updateCompanyData)
    {
    }

    public function addRow(int $line, array $values, string $status, array $messages): void
    {
        $this->rows[$line] = [
            'line' => $line,
            'name' => $values['full_name'] ?? '',
            'phone' => $values['phone'] ?? '',
            'username' => $values['username'] ?? '',
            'status' => $status,
            'messages' => array_values(array_filter($messages)),
        ];
        $this->stats['rows_total']++;
        if ($status === 'error') {
            $this->stats['rows_error']++;
        } elseif ($status === 'merged') {
            $this->stats['rows_merged']++;
        }
        if ($status !== 'error' && $messages !== []) {
            $this->stats['rows_with_warnings']++;
        }
        ksort($this->rows);
    }

    public function countSubscriber(string $action): void
    {
        $this->stats['subscribers_'.$action]++;
    }

    public function countAccount(string $action): void
    {
        $this->stats['accounts_'.$action]++;
    }

    public function hasChanges(): bool
    {
        return $this->stats['subscribers_create'] + $this->stats['subscribers_update'] + $this->stats['accounts_create'] + $this->stats['accounts_update'] > 0;
    }
}

<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gapless document numbers per type and year (R-2026-000001). The counter row is incremented
 * inside the caller's transaction, so a rolled-back document gives its number back.
 */
class Sequencer
{
    public const PREFIXES = [
        'receipt' => 'R',
        'debt' => 'D',
        'activation' => 'A',
        'transfer' => 'T',
        'expense' => 'E',
        'sale' => 'S',
        'fund_transfer' => 'F',
        'settlement' => 'K',
    ];

    public function next(string $docType, DateTimeInterface $at): string
    {
        $year = (int) $at->format('Y');

        $value = DB::selectOne(
            'INSERT INTO document_sequences (doc_type, year, last_value) VALUES (?, ?, 1)
             ON CONFLICT (doc_type, year) DO UPDATE SET last_value = document_sequences.last_value + 1
             RETURNING last_value',
            [$docType, $year],
        )->last_value;

        return sprintf('%s-%d-%06d', self::PREFIXES[$docType], $year, $value);
    }
}

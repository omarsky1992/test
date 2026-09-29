<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\PeriodType;
use App\Exceptions\BusinessRuleException;
use App\Models\ActivationPeriod;
use App\Models\LedgerEntry;
use App\Services\ActivationService;
use App\Services\Ledger;
use App\Services\Sequencer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The protections that live in PostgreSQL itself, independent of the application code.
 */
class DatabaseGuardsTest extends TestCase
{
    public function test_overlapping_periods_on_one_account_are_rejected_by_the_database(): void
    {
        $activation = app(ActivationService::class)->activate($this->account(), $this->plan(), ActivationKind::Full30);

        $this->expectException(QueryException::class);
        ActivationPeriod::create([
            'activation_id' => $activation->id, 'account_id' => $activation->account_id, 'period_type' => PeriodType::Initial7,
            'days' => 7, 'starts_at' => $activation->starts_at->addDays(3), 'ends_at' => $activation->starts_at->addDays(10), 'source_type' => 'test',
        ]);
    }

    public function test_editing_a_start_into_another_period_gives_a_readable_error(): void
    {
        $account = $this->account();
        $service = app(ActivationService::class);
        $service->activate($account, $this->plan(), ActivationKind::Full30);
        $second = $service->activate($account, $this->plan(), ActivationKind::Full30);

        $this->expectException(BusinessRuleException::class);
        $service->editStart($second, $second->starts_at->subDays(5), 'اختبار');
    }

    public function test_period_length_must_match_its_days(): void
    {
        $activation = app(ActivationService::class)->activate($this->account(), $this->plan(), ActivationKind::Full30);

        $this->expectException(QueryException::class);
        ActivationPeriod::create([
            'activation_id' => $activation->id, 'account_id' => $activation->account_id, 'period_type' => PeriodType::Extension23,
            'days' => 23, 'starts_at' => $activation->ends_at, 'ends_at' => $activation->ends_at->addDays(25), 'source_type' => 'test',
        ]);
    }

    public function test_ledger_lines_cannot_be_changed(): void
    {
        app(ActivationService::class)->activate($this->account(), $this->plan(), ActivationKind::Partial7);

        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->update(['debit' => 1]);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $this->account();

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_unbalanced_postings_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(Ledger::class)->post('test', now(), [
            ['account' => Ledger::AR_PRIMARY, 'debit' => 100],
            ['account' => Ledger::REV_OTHER, 'credit' => 90],
        ]);
    }

    public function test_unbalanced_lines_inserted_directly_are_refused_by_the_database(): void
    {
        $txn = app(Ledger::class)->post('test', now(), [
            ['account' => Ledger::AR_PRIMARY, 'debit' => 100],
            ['account' => Ledger::REV_OTHER, 'credit' => 100],
        ]);

        $this->expectException(QueryException::class);
        LedgerEntry::insert([
            'txn_id' => $txn->id, 'ledger_account_id' => app(Ledger::class)->accountId(Ledger::AR_PRIMARY),
            'debit' => 50, 'credit' => 0, 'currency_code' => 'IQD', 'occurred_at' => now(),
        ]);
    }

    public function test_document_numbers_are_sequential_per_year(): void
    {
        $sequencer = app(Sequencer::class);

        $this->assertSame('R-2026-000001', $sequencer->next('receipt', now()));
        $this->assertSame('R-2026-000002', $sequencer->next('receipt', now()));
        $this->assertSame('R-2027-000001', $sequencer->next('receipt', now()->addYear()));
    }
}

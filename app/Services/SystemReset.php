<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * «تصفير النظام»: removes trial and test operations so real work can start clean.
 *
 * Removed: every financial movement (ledger, receipts, debts, activations, transfers, expenses,
 * device sales, settlements), employee custody movements and advances, follow-ups, renewals,
 * sync and import runs, and document numbering. Optionally the subscribers and their accounts.
 * Kept: users (with the admin), roles and permissions, settings, plans, promotions, payment
 * methods, cash boxes and wallets (back to zero), expense categories, the audit log.
 */
class SystemReset
{
    public const OPERATION_TABLES = [
        'activation_dues', 'custody_handover_requests', 'ledger_entries', 'payment_allocations', 'payment_lines', 'debt_transfers', 'account_renewals', 'follow_ups',
        'settlement_distributions', 'employee_advance_repayments', 'device_notes', 'devices', 'payments', 'debts',
        'activation_periods', 'activations', 'device_sales', 'expenses', 'fund_transfers', 'company_settlements',
        'employee_advances', 'financial_transactions', 'sync_runs', 'import_runs', 'whatsapp_messages', 'document_sequences',
    ];

    public const SUBSCRIBER_TABLES = ['accounts', 'subscribers'];

    public function __construct(private Audit $audit)
    {
    }

    /**
     * @return array<string, int> rows removed per table
     */
    public function run(User $admin, string $password, bool $withSubscribers): array
    {
        if (! $admin->isAdmin()) {
            throw new BusinessRuleException('تصفير النظام للمدير فقط.');
        }
        if (! Hash::check($password, $admin->password)) {
            throw new BusinessRuleException('كلمة المرور غير صحيحة.');
        }

        $tables = [...self::OPERATION_TABLES, ...($withSubscribers ? self::SUBSCRIBER_TABLES : [])];

        return DB::transaction(function () use ($tables, $withSubscribers, $admin) {
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = DB::table($table)->count();
            }

            if ($withSubscribers) {
                // The audit log stays, without its links to subscribers that no longer exist.
                DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
                DB::table('audit_logs')->whereNotNull('subscriber_id')->update(['subscriber_id' => null]);
                DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');
            }

            // TRUNCATE skips the append-only row triggers on purpose: this is the one sanctioned wipe.
            DB::statement('TRUNCATE TABLE '.implode(', ', self::OPERATION_TABLES).' RESTART IDENTITY');
            if ($withSubscribers) {
                // The audit log keeps a foreign key to subscribers, so these two are deleted row by row.
                foreach (self::SUBSCRIBER_TABLES as $table) {
                    DB::table($table)->delete();
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), 1, false)");
                }
            }

            if (! $withSubscribers) {
                // Activation periods are gone; the service end falls back to the company's date.
                DB::table('accounts')->update(['service_ends_at' => DB::raw('external_ends_at')]);
            }

            $this->audit->log('system.reset', 'system', array_filter($counts), [
                'with_subscribers' => $withSubscribers, 'by' => $admin->name,
            ], 'تصفير النظام', source: 'web');

            return $counts;
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_ar', 100);
            $table->string('type', 10);
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->boolean('is_system')->default(true);
        });
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('asset','liability','equity','revenue','expense'))");

        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('kind', 12);
            $table->string('name', 100);
            $table->string('holder_name', 100)->nullable();
            $table->foreignId('ledger_account_id')->unique()->constrained('ledger_accounts');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_kind_check CHECK (kind IN ('cash','electronic','company'))");

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_type', 30);
            $table->timestampTz('occurred_at');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_txn_id')->nullable()->unique()->constrained('financial_transactions');
            $table->text('memo')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['source_type', 'source_id']);
            $table->index('occurred_at');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_id')->constrained('financial_transactions');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->char('currency_code', 3)->default('IQD');
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('debt_id')->nullable();

            $table->index(['ledger_account_id', 'occurred_at']);
            $table->index('debt_id');
            $table->index(['account_id', 'occurred_at']);
            $table->index(['subscriber_id', 'occurred_at']);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('subscriber_id')->references('id')->on('subscribers');
            $table->foreign('account_id')->references('id')->on('accounts');
        });
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (debit >= 0 AND credit >= 0 AND ((debit = 0) <> (credit = 0)))');

        // Every transaction must balance (checked at commit, so all lines can be inserted first).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ledger_check_balanced() RETURNS trigger AS $$
DECLARE diff bigint;
BEGIN
    SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) INTO diff
    FROM ledger_entries WHERE txn_id = NEW.txn_id;
    IF diff <> 0 THEN
        RAISE EXCEPTION 'Unbalanced financial transaction % (difference %)', NEW.txn_id, diff;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER ledger_entries_balanced
    AFTER INSERT ON ledger_entries
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION ledger_check_balanced();

CREATE OR REPLACE FUNCTION forbid_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER ledger_entries_append_only BEFORE UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION forbid_change();
CREATE TRIGGER financial_transactions_append_only BEFORE UPDATE OR DELETE ON financial_transactions
    FOR EACH ROW EXECUTE FUNCTION forbid_change();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS financial_transactions_append_only ON financial_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_append_only ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('money_accounts');
        Schema::dropIfExists('ledger_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_check_balanced()');
        DB::unprepared('DROP FUNCTION IF EXISTS forbid_change()');
    }
};

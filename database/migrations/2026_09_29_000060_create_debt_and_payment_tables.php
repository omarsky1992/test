<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('source', 15);
            $table->foreignId('activation_id')->nullable()->unique()->constrained('activations');
            $table->foreignId('device_sale_id')->nullable()->constrained('device_sales');
            $table->string('bucket', 10);
            $table->bigInteger('original_amount');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance');
            $table->char('currency_code', 3)->default('IQD');
            $table->timestampTz('debt_date');
            $table->date('due_date')->nullable();
            $table->string('status', 10)->default('open');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['account_id', 'status']);
            $table->index('debt_date');
            $table->index(['created_by', 'created_at']);
        });
        DB::statement("CREATE INDEX debts_open_by_bucket ON debts (bucket, status) WHERE status IN ('open','partial')");
        DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_source_check CHECK (source IN ('activation','manual','opening','device_sale'))");
        DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_bucket_check CHECK (bucket IN ('secondary','primary'))");
        DB::statement('ALTER TABLE debts ADD CONSTRAINT debts_amount_check CHECK (original_amount > 0 AND paid_amount BETWEEN 0 AND original_amount AND balance = original_amount - paid_amount)');
        DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_status_check CHECK (status IN ('open','partial','paid','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('debt_id')->references('id')->on('debts');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->unique();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('payment_type', 15);
            $table->string('method', 12);
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->string('receiver_name', 100)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IQD');
            $table->timestampTz('received_at');
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('received_at');
            $table->index(['created_by', 'received_at']);
            $table->index(['account_id', 'received_at']);
            $table->index('subscriber_id');
            $table->index(['money_account_id', 'received_at']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_type_check CHECK (payment_type IN ('debt_payment','advance'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash','electronic') AND (method <> 'electronic' OR receiver_name IS NOT NULL))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('posted','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('debt_id')->constrained('debts');
            $table->bigInteger('amount');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->boolean('is_reversed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('payment_id');
            $table->index('debt_id');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');

        Schema::create('debt_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('debt_id')->constrained('debts');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('activation_id')->nullable()->constrained('activations');
            $table->string('from_bucket', 10);
            $table->string('to_bucket', 10);
            $table->bigInteger('amount');
            $table->smallInteger('days_added')->default(0);
            $table->foreignId('period_id')->nullable()->constrained('activation_periods');
            $table->text('reason')->nullable();
            $table->timestampTz('performed_at');
            $table->foreignId('performed_by')->nullable()->constrained('users');
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->timestampsTz();

            $table->index('performed_at');
            $table->index('debt_id');
        });
        DB::statement("ALTER TABLE debt_transfers ADD CONSTRAINT debt_transfers_bucket_check CHECK (from_bucket IN ('secondary','primary') AND to_bucket IN ('secondary','primary') AND from_bucket <> to_bucket)");
        DB::statement('ALTER TABLE debt_transfers ADD CONSTRAINT debt_transfers_amount_check CHECK (amount > 0 AND days_added >= 0)');
        DB::statement("ALTER TABLE debt_transfers ADD CONSTRAINT debt_transfers_status_check CHECK (status IN ('posted','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_transfers');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropForeign(['debt_id']));
        Schema::dropIfExists('debts');
    }
};

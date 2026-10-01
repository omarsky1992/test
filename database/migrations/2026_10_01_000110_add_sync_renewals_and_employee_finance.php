<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A subscriber pulled from the company site may have no phone; it can be added by hand later.
        DB::statement('ALTER TABLE subscribers ALTER COLUMN phone DROP NOT NULL');
        DB::statement('ALTER TABLE subscribers ALTER COLUMN phone_normalized DROP NOT NULL');

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('port_number', 20)->nullable();
            // Days left on the company site at the last import or sync. A renewal is 0 → more than 0.
            $table->smallInteger('company_days_left')->nullable();
            // Set by the live sync only; an Excel import never overwrites data newer than its own.
            $table->timestampTz('company_synced_at')->nullable();
            $table->index('external_subscription_id');
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 10);
            $table->string('status', 10);
            $table->jsonb('stats')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });
        DB::statement("ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check CHECK (status IN ('running','success','failed'))");

        DB::statement('ALTER TABLE debts DROP CONSTRAINT debts_source_check');
        DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_source_check CHECK (source IN ('activation','manual','opening','device_sale','renewal'))");

        // One row per renewal seen on the company site. The reference makes a repeated scan harmless.
        Schema::create('account_renewals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs');
            $table->timestampTz('detected_at');
            $table->smallInteger('previous_days');
            $table->smallInteger('new_days');
            $table->timestampTz('previous_ends_at')->nullable();
            $table->timestampTz('new_ends_at')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('service_plans');
            $table->string('plan_name', 60)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->foreignId('debt_id')->nullable()->unique()->constrained('debts');
            $table->string('status', 15);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'detected_at']);
            $table->index('detected_at');
        });
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price'))");

        // Each employee's collections are held in a custody box of their own until handed to the cash box.
        DB::statement('ALTER TABLE money_accounts DROP CONSTRAINT money_accounts_kind_check');
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_kind_check CHECK (kind IN ('cash','electronic','company','custody'))");
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users');
        });
        DB::statement("CREATE UNIQUE INDEX money_accounts_custody_user_unique ON money_accounts (user_id) WHERE kind = 'custody'");
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_custody_user_check CHECK ((kind = 'custody') = (user_id IS NOT NULL))");

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('collects_to_custody')->default(true);
        });
        // The admin receives into the cash box directly.
        DB::statement("UPDATE users SET collects_to_custody = false WHERE id IN (
            SELECT model_id FROM model_has_roles JOIN roles ON roles.id = model_has_roles.role_id WHERE roles.name = 'admin')");

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->bigInteger('amount');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance');
            $table->timestampTz('advanced_at');
            $table->string('reason', 200);
            $table->text('details')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 10);
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index('advanced_at');
        });
        DB::statement('ALTER TABLE employee_advances ADD CONSTRAINT employee_advances_amount_check CHECK (amount > 0 AND paid_amount BETWEEN 0 AND amount AND balance = amount - paid_amount)');
        DB::statement("ALTER TABLE employee_advances ADD CONSTRAINT employee_advances_status_check CHECK (status IN ('unpaid','partial','paid'))");

        Schema::create('employee_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('advance_id')->constrained('employee_advances');
            $table->foreignId('user_id')->constrained('users');
            $table->bigInteger('amount');
            $table->timestampTz('paid_at');
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('advance_id');
            $table->index(['user_id', 'paid_at']);
        });
        DB::statement('ALTER TABLE employee_advance_repayments ADD CONSTRAINT employee_advance_repayments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_repayments');
        Schema::dropIfExists('employee_advances');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('collects_to_custody'));
        DB::statement('ALTER TABLE money_accounts DROP CONSTRAINT money_accounts_custody_user_check');
        DB::statement('DROP INDEX IF EXISTS money_accounts_custody_user_unique');
        Schema::table('money_accounts', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        DB::statement('ALTER TABLE money_accounts DROP CONSTRAINT money_accounts_kind_check');
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_kind_check CHECK (kind IN ('cash','electronic','company'))");
        Schema::dropIfExists('account_renewals');
        DB::statement('ALTER TABLE debts DROP CONSTRAINT debts_source_check');
        DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_source_check CHECK (source IN ('activation','manual','opening','device_sale'))");
        Schema::dropIfExists('sync_runs');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['external_subscription_id']);
            $table->dropColumn(['port_number', 'company_days_left', 'company_synced_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('activation_id')->nullable()->constrained('activations');
            $table->foreignId('debt_id')->nullable()->constrained('debts');
            $table->string('channel', 12);
            $table->string('outcome', 20);
            $table->timestampTz('promised_at')->nullable();
            $table->timestampTz('next_follow_up_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->index('next_follow_up_at');
        });
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_channel_check CHECK (channel IN ('call','whatsapp','visit','sms'))");
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_outcome_check CHECK (outcome IN ('no_answer','promised_to_pay','will_pay_today','refused','wrong_number','other'))");

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('device_type_id')->constrained('device_types');
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('serial_number', 60)->nullable()->index();
            $table->string('mac_address', 17)->nullable();
            $table->string('ownership', 15)->default('subscriber');
            $table->foreignId('device_sale_id')->nullable()->constrained('device_sales');
            $table->string('status', 15)->default('active');
            $table->timestampTz('installed_at')->nullable();
            $table->jsonb('specs')->default('{}');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_ownership_check CHECK (ownership IN ('subscriber','company_loan','sold'))");
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_status_check CHECK (status IN ('active','faulty','replaced','returned'))");

        Schema::create('device_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices');
            $table->text('note');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('from_money_account_id')->constrained('money_accounts');
            $table->foreignId('to_money_account_id')->constrained('money_accounts');
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IQD');
            $table->timestampTz('transferred_at');
            $table->string('reference', 100)->nullable();
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_check CHECK (amount > 0 AND from_money_account_id <> to_money_account_id)');

        Schema::create('company_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->date('period_from');
            $table->date('period_to');
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IQD');
            $table->foreignId('received_into_money_account_id')->constrained('money_accounts');
            $table->timestampTz('received_at');
            $table->integer('activations_count')->default(0);
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE company_settlements ADD CONSTRAINT company_settlements_check CHECK (amount > 0 AND period_to >= period_from)');

        Schema::create('settlement_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('company_settlements');
            $table->string('kind', 15);
            $table->string('beneficiary', 120)->nullable();
            $table->bigInteger('amount');
            $table->foreignId('from_money_account_id')->constrained('money_accounts');
            $table->foreignId('to_money_account_id')->nullable()->constrained('money_accounts');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE settlement_distributions ADD CONSTRAINT settlement_distributions_check CHECK (amount > 0 AND kind IN ('zone_fund','partner','salary','other') AND (kind <> 'zone_fund' OR to_money_account_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_distributions');
        Schema::dropIfExists('company_settlements');
        Schema::dropIfExists('fund_transfers');
        Schema::dropIfExists('device_notes');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('follow_ups');
    }
};

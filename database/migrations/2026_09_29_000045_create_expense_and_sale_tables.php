<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 80)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->string('description', 255);
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IQD');
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->string('paid_to', 120)->nullable();
            $table->timestampTz('spent_at');
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('spent_at');
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('posted','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");

        Schema::create('device_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name_ar', 60);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('device_sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers');
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->string('buyer_name', 120)->nullable();
            $table->foreignId('item_type_id')->constrained('device_types');
            $table->string('description', 255);
            $table->string('serial_number', 60)->nullable();
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('total_amount');
            $table->bigInteger('cost_amount')->nullable();
            $table->foreignId('purchase_expense_id')->nullable()->constrained('expenses');
            $table->string('settlement', 10);
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts');
            $table->timestampTz('sold_at');
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('sold_at');
        });
        DB::statement('ALTER TABLE device_sales ADD CONSTRAINT device_sales_amount_check CHECK (quantity > 0 AND unit_price >= 0 AND total_amount = quantity * unit_price AND (cost_amount IS NULL OR cost_amount >= 0))');
        DB::statement("ALTER TABLE device_sales ADD CONSTRAINT device_sales_settlement_check CHECK (settlement IN ('paid','debt') AND (settlement <> 'paid' OR money_account_id IS NOT NULL))");
        DB::statement("ALTER TABLE device_sales ADD CONSTRAINT device_sales_status_check CHECK (status IN ('posted','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sales');
        Schema::dropIfExists('device_types');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};

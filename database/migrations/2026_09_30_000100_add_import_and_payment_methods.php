<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company identifiers and the company's view of each subscription (from imports).
        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('external_id', 50)->nullable()->unique();
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('zone_code', 50)->nullable();
            $table->string('gps', 60)->nullable();
            $table->string('external_subscription_id', 50)->nullable();
            $table->string('external_plan', 60)->nullable();
            $table->string('external_status', 30)->nullable();
            $table->timestampTz('external_ends_at')->nullable();
            $table->timestampTz('external_synced_at')->nullable();
        });
        // A device can move to another subscriber, so a serial is searchable but not unique.
        DB::statement('DROP INDEX IF EXISTS accounts_serial_unique');
        DB::statement('CREATE INDEX accounts_serial_index ON accounts (serial_number)');

        // Payment methods are data, so new ones (a new wallet, a bank) need no code change.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_ar', 80);
            $table->string('category', 12);
            $table->boolean('requires_receiver')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_category_check CHECK (category IN ('cash','electronic','card','bank','other'))");

        // One receipt can be paid with several methods (e.g. part cash, part Zain Cash).
        Schema::create('payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->bigInteger('amount');
            $table->string('receiver_name', 100)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('payment_id');
            $table->index(['payment_method_id', 'created_at']);
        });
        DB::statement('ALTER TABLE payment_lines ADD CONSTRAINT payment_lines_amount_check CHECK (amount > 0)');

        // The receipt's method is now a summary of its lines ("mixed" when there are several).
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash','electronic','card','bank','other','mixed'))");

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('subscribers');
            $table->string('file_name', 200);
            $table->string('mode', 20);
            $table->jsonb('mapping');
            $table->jsonb('stats')->nullable();
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash','electronic') AND (method <> 'electronic' OR receiver_name IS NOT NULL))");
        Schema::dropIfExists('payment_lines');
        Schema::dropIfExists('payment_methods');
        DB::statement('DROP INDEX IF EXISTS accounts_serial_index');
        DB::statement('CREATE UNIQUE INDEX accounts_serial_unique ON accounts (serial_number) WHERE serial_number IS NOT NULL');
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['zone_code', 'gps', 'external_subscription_id', 'external_plan', 'external_status', 'external_ends_at', 'external_synced_at']));
        Schema::table('subscribers', fn (Blueprint $table) => $table->dropColumn('external_id'));
    }
};

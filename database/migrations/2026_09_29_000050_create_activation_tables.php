<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('plan_id')->constrained('service_plans');
            $table->foreignId('promotion_id')->nullable()->constrained('promotions');
            $table->string('kind', 10);
            $table->bigInteger('list_price');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('final_price');
            $table->bigInteger('company_cost');
            $table->char('currency_code', 3)->default('IQD');
            $table->string('settlement', 10);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('completion_status', 15);
            $table->timestampTz('completed_at')->nullable();
            $table->string('completed_via', 10)->nullable();
            $table->boolean('start_overridden')->default(false);
            $table->string('external_ref', 100)->nullable();
            $table->string('status', 10)->default('posted');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['account_id', 'starts_at']);
            $table->index('created_at');
            $table->index(['created_by', 'created_at']);
            $table->index('plan_id');
        });
        DB::statement('CREATE INDEX activations_pending ON activations (ends_at) WHERE completion_status = \'pending\' AND status = \'posted\'');
        DB::statement("ALTER TABLE activations ADD CONSTRAINT activations_kind_check CHECK (kind IN ('partial_7','full_30'))");
        DB::statement("ALTER TABLE activations ADD CONSTRAINT activations_settlement_check CHECK (settlement IN ('debt','paid','credit') AND (kind <> 'partial_7' OR settlement = 'debt'))");
        DB::statement("ALTER TABLE activations ADD CONSTRAINT activations_completion_check CHECK (completion_status IN ('not_required','pending','completed') AND (kind = 'partial_7') = (completion_status <> 'not_required'))");
        DB::statement("ALTER TABLE activations ADD CONSTRAINT activations_completed_via_check CHECK (completed_via IS NULL OR completed_via IN ('payment','transfer'))");
        DB::statement('ALTER TABLE activations ADD CONSTRAINT activations_price_check CHECK (list_price > 0 AND discount_amount >= 0 AND final_price = list_price - discount_amount AND final_price >= 0 AND company_cost >= 0)');
        DB::statement("ALTER TABLE activations ADD CONSTRAINT activations_status_check CHECK (status IN ('posted','voided') AND (status <> 'voided' OR void_reason IS NOT NULL))");

        Schema::create('activation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activation_id')->constrained('activations');
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('period_type', 15);
            $table->smallInteger('days');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_void')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('activation_id');
        });
        DB::statement("ALTER TABLE activation_periods ADD CONSTRAINT activation_periods_type_check CHECK (period_type IN ('initial_7','initial_30','extension_23'))");
        DB::statement("ALTER TABLE activation_periods ADD CONSTRAINT activation_periods_length_check CHECK (days > 0 AND ends_at = starts_at + make_interval(days => days))");
        // The core guarantee: one account never has two overlapping service periods.
        DB::statement("ALTER TABLE activation_periods ADD CONSTRAINT activation_periods_no_overlap EXCLUDE USING gist (account_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (NOT is_void)");
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_periods');
        Schema::dropIfExists('activations');
    }
};

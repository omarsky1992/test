<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar', 60);
            $table->bigInteger('price');
            $table->char('currency_code', 3)->default('IQD');
            $table->smallInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->foreign('currency_code')->references('code')->on('currencies');
        });
        DB::statement('ALTER TABLE service_plans ADD CONSTRAINT service_plans_price_check CHECK (price > 0)');

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreign('current_plan_id')->references('id')->on('service_plans');
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('audience', 20);
            $table->foreignId('plan_id')->nullable()->constrained('service_plans');
            $table->string('discount_type', 20);
            $table->bigInteger('discount_value');
            $table->string('funded_by', 10)->default('company');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_audience_check CHECK (audience IN ('new','existing','all'))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (discount_type IN ('fixed_price','amount_off','percent_off'))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_value_check CHECK (discount_value > 0 AND (discount_type <> 'percent_off' OR discount_value <= 100))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_funded_check CHECK (funded_by IN ('company','agent'))");
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_period_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::table('accounts', fn (Blueprint $table) => $table->dropForeign(['current_plan_id']));
        Schema::dropIfExists('service_plans');
    }
};

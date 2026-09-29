<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('code', 30)->unique();
            $table->string('full_name', 150);
            $table->string('name_search', 150);
            $table->string('phone', 20);
            $table->string('phone_normalized', 15);
            $table->string('alt_phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('phone_normalized');
            $table->index(['branch_id', 'status']);
        });
        DB::statement("ALTER TABLE subscribers ADD CONSTRAINT subscribers_status_check CHECK (status IN ('active','inactive','blocked','archived'))");
        DB::statement('CREATE INDEX subscribers_name_search_trgm ON subscribers USING gin (name_search gin_trgm_ops)');

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('username', 80);
            $table->text('secret_encrypted')->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_normalized', 15)->nullable();
            $table->string('fat_code', 50)->nullable();
            $table->string('pole_number', 50)->nullable();
            $table->string('location_label', 150)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('current_plan_id')->nullable();
            $table->timestampTz('service_ends_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('subscriber_id');
            $table->index('phone_normalized');
            $table->index('fat_code');
            $table->index('pole_number');
            $table->index('service_ends_at');
        });
        DB::statement('CREATE UNIQUE INDEX accounts_username_lower_unique ON accounts (lower(username))');
        DB::statement('CREATE UNIQUE INDEX accounts_serial_unique ON accounts (serial_number) WHERE serial_number IS NOT NULL');
        DB::statement('CREATE INDEX accounts_username_trgm ON accounts USING gin (lower(username) gin_trgm_ops)');
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_status_check CHECK (status IN ('active','suspended','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('subscribers');
    }
};

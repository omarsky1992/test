<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // «المبيعات الثانوية»: a log of its own, counted alone; it posts nothing and moves no balance.
    public function up(): void
    {
        Schema::create('secondary_sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('description', 200);
            $table->bigInteger('amount');
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->string('buyer_name', 120)->nullable();
            $table->timestampTz('sold_at');
            $table->string('status', 10)->default('posted');
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 300)->nullable();
            $table->timestampsTz();
            $table->index(['status', 'sold_at']);
        });
        DB::statement('ALTER TABLE secondary_sales ADD CONSTRAINT secondary_sales_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE secondary_sales ADD CONSTRAINT secondary_sales_status_check CHECK (status IN ('posted','voided'))");

        // Existing employee roles get the ones employees have by default (new installs get them from the seeder).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['secondary_sales.view', 'secondary_sales.create', 'secondary_sales.void', 'secondary_sales.reset'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if (DB::table('roles')->where('name', 'employee')->exists()) {
            Role::findByName('employee', 'web')->givePermissionTo(['secondary_sales.view', 'secondary_sales.create']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('secondary_sales');
    }
};

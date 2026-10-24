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
    // «تذكير تفعيل 7 أيام»: once the account's subscription ends, chosen staff numbers get «فعّل فلان 7 أيام».
    public function up(): void
    {
        Schema::create('activation_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->unsignedSmallInteger('days')->default(7);
            $table->jsonb('number_ids')->nullable();
            $table->timestampTz('ends_at_when_set')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index(['sent_at', 'cancelled_at']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('reminders.activation', 'web');
        if (DB::table('roles')->where('name', 'employee')->exists()) {
            Role::findByName('employee', 'web')->givePermissionTo('reminders.activation');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_reminders');
    }
};

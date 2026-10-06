<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Selling devices was checked against a permission missing from the catalogue, so only the
        // admin could sell. Existing employee roles get «sell» (new installs get it from the seeder).
        if (DB::table('roles')->where('name', 'employee')->exists()) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            \Spatie\Permission\Models\Permission::findOrCreate('sales.void', 'web');
            \Spatie\Permission\Models\Role::findByName('employee', 'web')
                ->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('sales.create', 'web'));
        }
    }

    public function down(): void
    {
    }
};

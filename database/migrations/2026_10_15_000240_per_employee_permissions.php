<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // A user's permissions = the role's, plus the user's own extra grants, minus what the admin took
        // away from that one user (so one employee can lose «المبيعات» while the others keep it).
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('denied_permissions')->default('[]');
        });

        // The new catalogue. Screens that were open to every employee stay open to them by default.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_keys(Permissions::ALL) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if (DB::table('roles')->where('name', 'employee')->exists()) {
            Role::findByName('employee', 'web')->givePermissionTo([
                'import.run', 'activations.view', 'transfers.view', 'payments.view', 'expenses.view', 'sales.view',
                'follow_ups.view', 'money_accounts.view', 'locations.view', 'locations.create', 'locations.update',
                'locations.directions', 'photos.view', 'photos.add',
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('denied_permissions'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // New permissions; existing employee roles get the ones employees have by default (new installs
        // get them from the seeder). Deleting notes, managing the blacklist and bulk messages stay off.
        if (! DB::table('roles')->where('name', 'employee')->exists()) {
            return;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['notes.delete', 'blacklist.manage', 'whatsapp.broadcast'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::findByName('employee', 'web');
        foreach (['notes.view', 'notes.create', 'notes.close', 'blacklist.view'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
    }

    public function down(): void {}
};

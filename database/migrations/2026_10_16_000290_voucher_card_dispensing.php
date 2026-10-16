<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // «إنشاء» a card from a category: the key of the click that took it, so the same click never takes two.
        Schema::table('voucher_cards', function (Blueprint $table) {
            $table->uuid('dispense_key')->nullable()->unique();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('stock.dispense', 'web');
        Permission::findOrCreate('stock.history', 'web');
    }

    public function down(): void
    {
        Schema::table('voucher_cards', fn (Blueprint $table) => $table->dropColumn('dispense_key'));
    }
};

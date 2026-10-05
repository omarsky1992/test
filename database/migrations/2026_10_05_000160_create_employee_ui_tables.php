<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each user's own colour, and for the admin which interface they are using.
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme_color', 20)->nullable();
            $table->string('ui_mode', 10)->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_ui_mode_check CHECK (ui_mode IS NULL OR ui_mode IN ('admin','employee'))");

        // «يجب التفعيل»: a secondary debt paid in full, waiting for the full activation on the company
        // site. Closed when the company end date moves forward, or by hand.
        Schema::create('activation_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->unique()->constrained('debts');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->bigInteger('amount');
            $table->timestampTz('paid_at');
            $table->timestampTz('ends_at_when_paid')->nullable();
            $table->string('status', 10);
            $table->string('resolved_via', 10)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['status', 'paid_at']);
            $table->index(['account_id', 'status']);
        });
        DB::statement("ALTER TABLE activation_dues ADD CONSTRAINT activation_dues_status_check CHECK (status IN ('pending','done','cancelled'))");
        DB::statement("ALTER TABLE activation_dues ADD CONSTRAINT activation_dues_via_check CHECK (resolved_via IS NULL OR resolved_via IN ('sync','manual','void'))");

        // An employee says they handed their custody over; the money moves only when the admin confirms.
        Schema::create('custody_handover_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->bigInteger('amount');
            $table->text('notes')->nullable();
            $table->string('status', 10);
            $table->timestampTz('requested_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('fund_transfer_id')->nullable()->constrained('fund_transfers');
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'requested_at']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE custody_handover_requests ADD CONSTRAINT custody_handover_requests_status_check CHECK (status IN ('pending','approved','rejected'))");
        DB::statement('ALTER TABLE custody_handover_requests ADD CONSTRAINT custody_handover_requests_amount_check CHECK (amount > 0)');

        // Fixed WhatsApp messages the admin writes; employees pick one before sending.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->string('icon', 10)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        // Existing employee roles get the two new everyday permissions (new installs get them from the seeder).
        if (DB::table('roles')->where('name', 'employee')->exists()) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $role = \Spatie\Permission\Models\Role::findByName('employee', 'web');
            foreach (['activations.mark_done', 'whatsapp.remind'] as $name) {
                $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($name, 'web'));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('custody_handover_requests');
        Schema::dropIfExists('activation_dues');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_ui_mode_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['theme_color', 'ui_mode']));
    }
};

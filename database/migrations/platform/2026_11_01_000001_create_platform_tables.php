<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform database (connection «platform»): the clients (tenants) with their subscriptions, the
 * platform owner's accounts, the owner's audit log and the subscription alerts already raised.
 * Run only by `platform:install`; a client's own tables live in that client's database.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 120);
            $table->string('owner_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            // The host the client opens (client 1 keeps the original address).
            $table->string('domain', 190)->nullable()->unique();
            // Its own database on the same server.
            $table->string('database', 63)->unique();
            // active | suspended (by the owner) | archived (kept, never deleted).
            $table->string('status', 12)->default('active');
            // The owner's own business: never expires.
            $table->boolean('is_permanent')->default(false);
            $table->string('plan', 40)->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('subscription_starts_at')->nullable();
            $table->timestampTz('subscription_ends_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->text('notes')->nullable();
            // Counts refreshed from the client's database, for the owner's dashboard.
            $table->unsignedInteger('subscribers_count')->default(0);
            $table->unsignedInteger('users_count')->default(0);
            $table->timestampTz('stats_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        DB::connection('platform')->statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','suspended','archived'))");

        Schema::connection('platform')->create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('username', 60);
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        DB::connection('platform')->statement('CREATE UNIQUE INDEX platform_admins_username_lower_unique ON platform_admins (lower(username))');

        Schema::connection('platform')->create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at');
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            $table->string('action', 60);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
        });
        // Append-only, like the clients' own audit log.
        DB::connection('platform')->unprepared(<<<'SQL'
            CREATE FUNCTION platform_audit_append_only() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'platform_audit_logs is append-only'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER platform_audit_logs_append_only BEFORE UPDATE OR DELETE ON platform_audit_logs
                FOR EACH ROW EXECUTE FUNCTION platform_audit_append_only();
            SQL);

        // One row per alert raised («7 days left»…), so a scheduled run never raises it twice.
        Schema::connection('platform')->create('tenant_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('kind', 30);
            $table->timestampTz('ends_at');
            $table->smallInteger('days_before');
            $table->timestampTz('seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'kind', 'ends_at', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('tenant_alerts');
        Schema::connection('platform')->dropIfExists('platform_audit_logs');
        DB::connection('platform')->unprepared('DROP FUNCTION IF EXISTS platform_audit_append_only() CASCADE');
        Schema::connection('platform')->dropIfExists('platform_admins');
        Schema::connection('platform')->dropIfExists('tenants');
    }
};

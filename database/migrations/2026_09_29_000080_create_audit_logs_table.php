<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 60);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 10)->default('web');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('request_id')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'occurred_at']);
            $table->index('occurred_at');
            $table->index('action');
            $table->index(['subscriber_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_source_check CHECK (source IN ('web','api','sync','system'))");
        DB::unprepared('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION forbid_change()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phone numbers allowed to control the system over WhatsApp. Each acts as a user and
        // carries that user's permissions.
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('label', 100)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        // Every message received, with what it was understood as and what was done. The WhatsApp
        // message ID is unique so a delivery repeated by Meta is never executed twice.
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wa_message_id', 150)->unique();
            $table->string('from_phone', 20);
            $table->foreignId('whatsapp_number_id')->nullable()->constrained('whatsapp_numbers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('type', 15);
            $table->text('body')->nullable();
            $table->text('transcript')->nullable();
            $table->string('intent', 30)->nullable();
            $table->jsonb('command')->nullable();
            $table->string('status', 15);
            $table->jsonb('result')->nullable();
            $table->text('reply')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['from_phone', 'received_at']);
            $table->index('received_at');
        });
        DB::statement("ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_status_check CHECK (status IN ('received','done','clarify','denied','failed','unauthorized','ignored'))");

        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_source_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_source_check CHECK (source IN ('web','api','sync','system','whatsapp'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_source_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_source_check CHECK (source IN ('web','api','sync','system'))");
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_numbers');
    }
};

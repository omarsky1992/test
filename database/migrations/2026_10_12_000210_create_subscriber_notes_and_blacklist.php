<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «وضع المشترك»: notes about a subscriber. Closing keeps the note in the history; deleting is a
        // separate, permitted step that only hides it (who and when are kept). Notes touch no money.
        Schema::create('subscriber_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->text('body');
            $table->string('status', 10)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('close_reason', 300)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users');

            $table->index(['subscriber_id', 'created_at']);
        });
        DB::statement("ALTER TABLE subscriber_notes ADD CONSTRAINT subscriber_notes_status_check CHECK (status IN ('active','closed'))");
        DB::statement("CREATE INDEX subscriber_notes_active_index ON subscriber_notes (subscriber_id) WHERE status = 'active' AND deleted_at IS NULL");

        // «القائمة السوداء»: internal follow-up only, by the subscriber's id. Removing keeps the entry
        // (who, when, why); a subscriber is on the list at most once at a time.
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers');
            $table->string('reason_type', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users');
            $table->timestampTz('added_at')->useCurrent();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users');
            $table->string('remove_reason', 300)->nullable();
        });
        DB::statement("ALTER TABLE blacklist_entries ADD CONSTRAINT blacklist_entries_reason_type_check CHECK (reason_type IN ('late_payments','broken_promises','other'))");
        DB::statement('CREATE UNIQUE INDEX blacklist_entries_one_active ON blacklist_entries (subscriber_id) WHERE removed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_entries');
        Schema::dropIfExists('subscriber_notes');
    }
};

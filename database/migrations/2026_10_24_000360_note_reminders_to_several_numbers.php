<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A note reminder goes to several chosen numbers (empty = all authorized numbers), not just one.
    public function up(): void
    {
        Schema::table('subscriber_notes', fn (Blueprint $table) => $table->jsonb('remind_number_ids')->nullable());
        DB::statement('UPDATE subscriber_notes SET remind_number_ids = jsonb_build_array(remind_number_id) WHERE remind_number_id IS NOT NULL');
        Schema::table('subscriber_notes', fn (Blueprint $table) => $table->dropConstrainedForeignId('remind_number_id'));
    }

    public function down(): void
    {
        Schema::table('subscriber_notes', fn (Blueprint $table) => $table->foreignId('remind_number_id')->nullable()->constrained('whatsapp_numbers')->nullOnDelete());
        DB::statement("UPDATE subscriber_notes SET remind_number_id = (remind_number_ids->>0)::bigint WHERE jsonb_array_length(coalesce(remind_number_ids, '[]')) > 0");
        Schema::table('subscriber_notes', fn (Blueprint $table) => $table->dropColumn('remind_number_ids'));
    }
};

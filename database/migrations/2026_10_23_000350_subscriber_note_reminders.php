<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A note can carry a WhatsApp reminder at a chosen time, to all authorized numbers or one of them.
    public function up(): void
    {
        Schema::table('subscriber_notes', function (Blueprint $table) {
            $table->timestampTz('remind_at')->nullable();
            $table->foreignId('remind_number_id')->nullable()->constrained('whatsapp_numbers')->nullOnDelete();
            $table->timestampTz('reminded_at')->nullable();
            $table->index(['remind_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriber_notes', function (Blueprint $table) {
            $table->dropIndex(['remind_at', 'reminded_at']);
            $table->dropConstrainedForeignId('remind_number_id');
            $table->dropColumn(['remind_at', 'reminded_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reminder can go some hours before the subscription ends (0 = once it has ended, as before),
     * with its own title (e.g. «فعله ابو حسن» from the WhatsApp command «قرضه فلان»).
     */
    public function up(): void
    {
        Schema::table('activation_reminders', function (Blueprint $table) {
            $table->unsignedSmallInteger('hours_before')->default(0);
            $table->string('title', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activation_reminders', fn (Blueprint $table) => $table->dropColumn(['hours_before', 'title']));
    }
};

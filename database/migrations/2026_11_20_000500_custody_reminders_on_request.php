<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «تنزيل عهدة فلان» by WhatsApp asks for a custody on demand: such a row sits beside the daily
 * reminder of the same day, so only the daily ones stay one per employee, number and day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_reminders', function (Blueprint $table) {
            $table->string('source', 10)->default('daily'); // daily, request
            $table->dropUnique(['user_id', 'whatsapp_number_id', 'reminded_on']);
        });
        DB::statement("create unique index custody_reminders_daily_unique on custody_reminders (user_id, whatsapp_number_id, reminded_on) where source = 'daily'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists custody_reminders_daily_unique');
        Schema::table('custody_reminders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

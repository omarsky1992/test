<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // «القدرة المستلمة»: the last fibre signal reading (dBm) of each account's ONT, and its status, as the company site gave them.
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('rx_power', 6, 2)->nullable();
            $table->string('ont_status', 30)->nullable();
            $table->timestampTz('rx_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['rx_power', 'ont_status', 'rx_read_at']));
    }
};

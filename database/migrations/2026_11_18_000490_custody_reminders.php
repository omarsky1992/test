<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «تذكير تنزيل العهدة»: one WhatsApp reminder a day per employee custody to each admin number;
 * «نعم» or an amount in reply hands it over to the main cash box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('whatsapp_number_id')->constrained('whatsapp_numbers');
            $table->date('reminded_on');
            $table->bigInteger('balance');
            $table->string('status', 10)->default('sent'); // sent, done, expired
            $table->bigInteger('amount')->nullable();
            $table->foreignId('fund_transfer_id')->nullable()->constrained('fund_transfers');
            $table->timestampTz('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['user_id', 'whatsapp_number_id', 'reminded_on']);
            $table->index(['whatsapp_number_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_reminders');
    }
};

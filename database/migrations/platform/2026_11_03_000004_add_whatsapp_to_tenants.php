<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each client's own WhatsApp (QR) service: its address inside the server and its keys (encrypted
 * with APP_KEY). Client 1 keeps the server's own service from the environment.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->table('tenants', function (Blueprint $table) {
            $table->string('waha_url', 120)->nullable();
            $table->text('waha_api_key')->nullable();
            $table->text('waha_webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('tenants', fn (Blueprint $table) => $table->dropColumn(['waha_url', 'waha_api_key', 'waha_webhook_secret']));
    }
};

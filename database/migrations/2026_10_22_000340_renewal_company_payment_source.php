<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // How the company site says a renewal was paid (its wallet log): by card or from the wallet.
    public function up(): void
    {
        Schema::table('account_renewals', function (Blueprint $table) {
            $table->string('payment_source', 10)->nullable();
            $table->string('company_txn_id', 60)->nullable();
            $table->bigInteger('company_amount')->nullable();
        });
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_payment_source_check CHECK (payment_source IN ('card','wallet'))");
    }

    public function down(): void
    {
        Schema::table('account_renewals', fn (Blueprint $table) => $table->dropColumn(['payment_source', 'company_txn_id', 'company_amount']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short activation (7 days) found by a sync waits for a yes/no before it becomes a secondary debt
     * (`pending_debt` → `debt_created` or `no_debt`); a subscriber first seen already activated is marked
     * `is_new`; the wallet log tells the agent's wallet from the subscriber's own.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price','activated','test','pending_debt','no_debt'))");

        Schema::table('account_renewals', function (Blueprint $table) {
            $table->boolean('is_new')->default(false);
            $table->string('payment_source', 20)->nullable()->change();
            $table->index(['status', 'detected_at']);
        });
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_payment_source_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_payment_source_check CHECK (payment_source IN ('card','wallet','wallet_partner','wallet_customer'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE account_renewals SET payment_source = 'wallet' WHERE payment_source IN ('wallet_partner','wallet_customer')");
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_payment_source_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_payment_source_check CHECK (payment_source IN ('card','wallet'))");
        DB::statement("UPDATE account_renewals SET status = 'no_price' WHERE status IN ('pending_debt','no_debt')");
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price','activated','test'))");
        Schema::table('account_renewals', function (Blueprint $table) {
            $table->dropIndex(['status', 'detected_at']);
            $table->dropColumn('is_new');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «تفعيل نقدي»: money received beyond the open debts is no longer kept as advance credit; it is the
 * cash price of an activation. Each receipt remembers how much of it was that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('activation_amount')->default(0);
        });
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_type_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_type_check CHECK (payment_type IN ('debt_payment','advance','activation'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_activation_amount_check CHECK (activation_amount >= 0 AND activation_amount <= amount)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_activation_amount_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_type_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_type_check CHECK (payment_type IN ('debt_payment','advance'))");
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('activation_amount');
        });
    }
};

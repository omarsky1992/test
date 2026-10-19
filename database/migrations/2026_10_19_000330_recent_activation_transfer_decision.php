<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // «المفعّل حديثاً» gains «مناقلة»: the unpaid secondary debt moves to the primary debts.
    public function up(): void
    {
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_decision_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_decision_check CHECK (decision IN ('debt','cash','transfer','earlier'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE account_renewals SET decision = 'debt' WHERE decision = 'transfer'");
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_decision_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_decision_check CHECK (decision IN ('debt','cash','earlier'))");
    }
};

<?php

use App\Services\RenewalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // A renewal longer than the short (7-day) activation is a full activation: recorded, no debt.
    public function up(): void
    {
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price','activated'))");

        // Debts already recorded for such renewals are voided (unless something was paid on them).
        app(RenewalService::class)->reclassifyFullActivations();
    }

    public function down(): void
    {
        DB::statement("UPDATE account_renewals SET status = 'no_price' WHERE status = 'activated'");
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price'))");
    }
};

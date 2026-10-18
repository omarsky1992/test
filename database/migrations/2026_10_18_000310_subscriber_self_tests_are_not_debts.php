<?php

use App\Services\RenewalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // A sync renewal of fewer than 4 days is the subscriber's own test: recorded, no debt.
    public function up(): void
    {
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price','activated','test'))");

        // Debts a sync already recorded for such tests are voided (unless something was paid on them).
        app(RenewalService::class)->reclassifySelfTests();
    }

    public function down(): void
    {
        DB::statement("UPDATE account_renewals SET status = 'no_price' WHERE status = 'test'");
        DB::statement('ALTER TABLE account_renewals DROP CONSTRAINT account_renewals_status_check');
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_status_check CHECK (status IN ('debt_created','no_price','activated'))");
    }
};

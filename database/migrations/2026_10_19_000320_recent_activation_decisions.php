<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // «المفعّل حديثاً»: a full activation found by a sync waits for someone to say debt (primary) or cash.
    public function up(): void
    {
        Schema::table('account_renewals', function (Blueprint $table) {
            $table->string('decision', 10)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decision_debt_id')->nullable()->constrained('debts')->nullOnDelete();
        });
        DB::statement("ALTER TABLE account_renewals ADD CONSTRAINT account_renewals_decision_check CHECK (decision IN ('debt','cash','earlier'))");
        // Activations found before this list existed are not brought back to it.
        DB::table('account_renewals')->where('status', 'activated')->update(['decision' => 'earlier']);
    }

    public function down(): void
    {
        Schema::table('account_renewals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_debt_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decision', 'decided_at']);
        });
    }
};

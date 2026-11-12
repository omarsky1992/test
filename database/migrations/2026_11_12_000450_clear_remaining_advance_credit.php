<?php

use App\Models\Account;
use App\Services\Audit;
use App\Services\PaymentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The advance credit is removed for good: whatever is still on an account becomes a cash activation
 * (the same `credit_cleared` posting as «حذف الأرصدة المقدمة»; no box, debt or receipt amount changes,
 * nothing is deleted). Runs once; a database with no credit (a new client) does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('ledger_accounts')->where('code', 'CUSTOMER_CREDIT')->exists()) {
            return;
        }
        $payments = app(PaymentService::class);
        $credits = $payments->creditAccounts();
        if ($credits->isEmpty()) {
            return;
        }
        app(Audit::class)->withSource('system', function () use ($credits, $payments) {
            foreach (Account::whereIn('id', $credits->keys())->get() as $account) {
                $payments->clearCredit($account, 'حذف الرصيد المقدم نهائياً من النظام');
            }
        });
    }

    public function down(): void
    {
        // Postings are append-only: nothing to undo here.
    }
};

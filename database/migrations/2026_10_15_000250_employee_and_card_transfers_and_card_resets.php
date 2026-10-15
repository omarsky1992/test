<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a transfer is: between boxes (as before), between two employees' custody, or between two
        // cards/wallets. A key per request so a double click never transfers twice.
        Schema::table('fund_transfers', function (Blueprint $table) {
            $table->string('kind', 10)->default('box');
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->index(['kind', 'transferred_at']);
        });
        DB::statement("ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_kind_check CHECK (kind IN ('box','custody','card'))");
        DB::statement("UPDATE fund_transfers t SET kind = 'custody' FROM money_accounts f, money_accounts o
            WHERE f.id = t.from_money_account_id AND o.id = t.to_money_account_id AND f.kind = 'custody' AND o.kind = 'custody'");
        DB::statement("UPDATE fund_transfers t SET kind = 'card' FROM money_accounts f, money_accounts o
            WHERE f.id = t.from_money_account_id AND o.id = t.to_money_account_id AND f.kind = 'electronic' AND o.kind = 'electronic'");

        // «تصفير رصيد البطاقة»: a documented adjustment that brings a card's balance to zero. Its
        // movements stay; the adjustment itself can be reversed.
        Schema::create('money_account_resets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->bigInteger('amount');
            $table->text('reason');
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('status', 10)->default('posted');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE money_account_resets ADD CONSTRAINT money_account_resets_amount_check CHECK (amount <> 0)');

        if (DB::table('ledger_accounts')->where('code', 'CARD_ADJUSTMENTS')->doesntExist()) {
            DB::table('ledger_accounts')->insert([
                'code' => 'CARD_ADJUSTMENTS', 'name_ar' => 'تسويات تصفير البطاقات', 'type' => 'equity', 'is_system' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('money_account_resets');
        Schema::table('fund_transfers', function (Blueprint $table) {
            $table->dropColumn(['kind', 'idempotency_key']);
        });
    }
};

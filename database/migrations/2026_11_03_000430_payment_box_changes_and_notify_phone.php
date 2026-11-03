<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // «تصحيح الصندوق»: each correction of where a receipt's money went (the ledger has its own
    // posting; this keeps who, when, from what to what and why). And which of a subscriber's two
    // numbers gets the WhatsApp messages.
    public function up(): void
    {
        Schema::create('payment_box_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('payment_line_id')->nullable()->constrained('payment_lines');
            $table->foreignId('from_money_account_id')->constrained('money_accounts');
            $table->foreignId('to_money_account_id')->constrained('money_accounts');
            $table->foreignId('from_method_id')->nullable()->constrained('payment_methods');
            $table->foreignId('to_method_id')->nullable()->constrained('payment_methods');
            $table->bigInteger('amount');
            $table->foreignId('txn_id')->nullable()->constrained('financial_transactions');
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at');
        });
        Schema::table('subscribers', function (Blueprint $table) {
            $table->boolean('notify_alt_phone')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', fn (Blueprint $table) => $table->dropColumn('notify_alt_phone'));
        Schema::dropIfExists('payment_box_changes');
    }
};

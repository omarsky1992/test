<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «مخزون الكارتات»: internet and sports-channel cards. A code exists once; a card is activated
        // once (available → activated), or set aside as void (damaged, a mistake).
        Schema::create('voucher_cards', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->bigInteger('denomination');
            $table->string('code', 100)->unique();
            $table->string('status', 10)->default('available');
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 300)->nullable();

            $table->index(['type', 'denomination', 'status']);
        });
        DB::statement("ALTER TABLE voucher_cards ADD CONSTRAINT voucher_cards_type_check CHECK (type IN ('internet','sports'))");
        DB::statement("ALTER TABLE voucher_cards ADD CONSTRAINT voucher_cards_status_check CHECK (status IN ('available','activated','void'))");
        DB::statement('ALTER TABLE voucher_cards ADD CONSTRAINT voucher_cards_denomination_check CHECK (denomination > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_cards');
    }
};

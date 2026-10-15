<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Closing a purchases or sales period: a snapshot of its totals. Nothing is deleted or moved,
        // no balance changes; the next period starts the day after.
        Schema::create('period_closings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('total');
            $table->integer('operations');
            $table->jsonb('breakdown');
            $table->uuid('batch')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->useCurrent();

            $table->unique(['scope', 'starts_on']);
        });
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_scope_check CHECK (scope IN ('purchases','sales'))");
        DB::statement('ALTER TABLE period_closings ADD CONSTRAINT period_closings_dates_check CHECK (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('period_closings');
    }
};

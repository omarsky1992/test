<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name_ar', 50);
            $table->smallInteger('minor_unit');
            $table->string('symbol', 10);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        DB::statement('CREATE UNIQUE INDEX currencies_single_default ON currencies (is_default) WHERE is_default');

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('doc_type', 20);
            $table->smallInteger('year');
            $table->bigInteger('last_value')->default(0);
            $table->primary(['doc_type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('currencies');
    }
};

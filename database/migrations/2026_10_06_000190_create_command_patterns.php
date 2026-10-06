<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin's own phrasings for WhatsApp commands: «تفعيل {الاسم} {الأيام}» → activation, etc.
        Schema::create('command_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 200);
            $table->string('action', 40);
            $table->smallInteger('default_days')->nullable();
            $table->boolean('amount_in_thousands')->default(false);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        $now = now();
        foreach ([
            ['تفعيل {الاسم} {الأيام}', 'activate', null, false],
            ['تفعيل {الاسم}', 'activate', 7, false],
            ['قبض {الاسم} {المبلغ}', 'payment', null, true],
            ['دين اولي {الاسم} {المبلغ}', 'add_debt_primary', null, true],
            ['دين ثانوي {الاسم} {المبلغ}', 'add_debt_secondary', null, true],
            ['مناقلة {الاسم}', 'transfer', null, false],
        ] as $i => [$pattern, $action, $days, $thousands]) {
            \Illuminate\Support\Facades\DB::table('command_patterns')->insert([
                'pattern' => $pattern, 'action' => $action, 'default_days' => $days, 'amount_in_thousands' => $thousands,
                'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('command_patterns');
    }
};

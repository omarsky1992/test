<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // «تفعيل فلان دين اولي»: a 30-day activation owed as a primary debt (the plan price unless an
        // amount is given). Tried before «تفعيل {الاسم}», added once.
        $now = now();
        foreach ([
            'تفعيل {الاسم} {الأيام} دين اولي {المبلغ}',
            'تفعيل {الاسم} {الأيام} دين اولي',
            'تفعيل {الاسم} دين اولي {المبلغ}',
            'تفعيل {الاسم} دين اولي',
        ] as $pattern) {
            if (DB::table('command_patterns')->where('pattern', $pattern)->doesntExist()) {
                DB::table('command_patterns')->insert([
                    'pattern' => $pattern, 'action' => 'activate_primary', 'default_days' => null, 'amount_in_thousands' => true,
                    'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('command_patterns')->where('action', 'activate_primary')->delete();
    }
};

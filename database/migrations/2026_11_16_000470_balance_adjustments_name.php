<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CARD_ADJUSTMENTS now also takes «تصفير الرصيد الكلي» of every box: only its Arabic name, if unchanged.
    public function up(): void
    {
        DB::table('ledger_accounts')->where('code', 'CARD_ADJUSTMENTS')->where('name_ar', 'تسويات تصفير البطاقات')->update(['name_ar' => 'تسويات تصفير الأرصدة']);
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->where('code', 'CARD_ADJUSTMENTS')->where('name_ar', 'تسويات تصفير الأرصدة')->update(['name_ar' => 'تسويات تصفير البطاقات']);
    }
};

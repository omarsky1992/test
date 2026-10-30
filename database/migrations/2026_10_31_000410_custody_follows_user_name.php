<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Custody boxes named after a user who was renamed since: they take the user's current name.
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE money_accounts m SET name = LEFT('عهدة ' || u.name, 100), holder_name = LEFT(u.name, 100), updated_at = now()
            FROM users u WHERE m.kind = 'custody' AND m.user_id = u.id AND m.holder_name IS DISTINCT FROM u.name
            SQL);
        DB::statement(<<<'SQL'
            UPDATE ledger_accounts l SET name_ar = m.name
            FROM money_accounts m WHERE m.kind = 'custody' AND m.ledger_account_id = l.id AND l.name_ar IS DISTINCT FROM m.name
            SQL);
    }

    public function down(): void {}
};

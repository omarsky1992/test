<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT backup_runs_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_check CHECK (trigger IN ('schedule','web','manual','cli','download') AND status IN ('running','success','failed'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM backup_runs WHERE trigger = 'download'");
        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT backup_runs_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_check CHECK (trigger IN ('schedule','web','manual','cli') AND status IN ('running','success','failed'))");
    }
};

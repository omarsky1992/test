<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 10);
            $table->string('status', 10);
            $table->string('file_name', 120)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('drive_file_id', 120)->nullable();
            $table->string('drive_link', 255)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index('started_at');
        });
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_check CHECK (trigger IN ('schedule','web','manual','cli') AND status IN ('running','success','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};

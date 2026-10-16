<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «تصفير» can happen in the middle of a day: periods start and end at the exact moment, so what
        // is recorded after a reset that same day belongs to the new period (and nothing falls between).
        Schema::table('period_closings', function (Blueprint $table) {
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
        });
        $tz = config('app.timezone');
        DB::statement("UPDATE period_closings SET starts_at = (starts_on::timestamp AT TIME ZONE '{$tz}'),
            ends_at = ((ends_on + 1)::timestamp AT TIME ZONE '{$tz}')");
        DB::statement('ALTER TABLE period_closings ALTER COLUMN starts_at SET NOT NULL, ALTER COLUMN ends_at SET NOT NULL');
        Schema::table('period_closings', function (Blueprint $table) {
            $table->dropUnique(['scope', 'starts_on']);
            $table->unique(['scope', 'starts_at']);
        });
        DB::statement('ALTER TABLE period_closings ADD CONSTRAINT period_closings_times_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE period_closings DROP CONSTRAINT IF EXISTS period_closings_times_check');
        Schema::table('period_closings', function (Blueprint $table) {
            $table->dropUnique(['scope', 'starts_at']);
            $table->unique(['scope', 'starts_on']);
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};

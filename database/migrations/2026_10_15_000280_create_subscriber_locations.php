<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The subscriber's house: coordinates saved on site (phone GPS, corrected on the map if needed)
        // and an optional photo, so any employee can find the house later. One row per subscriber;
        // every change is in the audit log.
        Schema::create('subscriber_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained('subscribers');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('accuracy_m')->nullable();
            $table->string('source', 8)->nullable();
            $table->foreignId('saved_by')->nullable()->constrained('users');
            $table->timestampTz('saved_at')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('photo_mime', 40)->nullable();
            $table->foreignId('photo_by')->nullable()->constrained('users');
            $table->timestampTz('photo_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE subscriber_locations ADD CONSTRAINT subscriber_locations_coords_check CHECK (
            (latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement("ALTER TABLE subscriber_locations ADD CONSTRAINT subscriber_locations_source_check CHECK (source IS NULL OR source IN ('gps','map'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_locations');
    }
};

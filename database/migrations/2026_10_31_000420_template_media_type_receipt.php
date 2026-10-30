<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Room for «receipt_image» / «receipt_pdf»: the payment message carrying the receipt itself.
    public function up(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->string('media_type', 20)->default('text')->change());
    }

    public function down(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->string('media_type', 10)->default('text')->change());
    }
};

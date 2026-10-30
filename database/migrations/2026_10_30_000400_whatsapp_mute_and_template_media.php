<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // «إيقاف رسائل الواتساب» per subscriber; a template sent as text, an image or a PDF; and the end
    // hour in the starter «قرب نهاية الاشتراك» message (only when the admin has not changed it).
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->boolean('whatsapp_muted')->default(false);
            $table->timestampTz('whatsapp_muted_at')->nullable();
            $table->foreignId('whatsapp_muted_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('media_type', 10)->default('text');
            $table->string('media_path')->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('media_name')->nullable();
        });
        DB::table('message_templates')->where('auto_event', 'expiring')
            ->where('body', "تحية طيبة {الاسم}،\nاشتراككم ({الفئة}) ينتهي بعد {الأيام} يوم بتاريخ {تاريخ_الانتهاء}.\nللتجديد يرجى التواصل معنا.")
            ->update(['body' => "تحية طيبة {الاسم}،\nاشتراككم ({الفئة}) ينتهي بعد {الأيام} يوم بتاريخ {تاريخ_الانتهاء} الساعة {ساعة_الانتهاء}.\nللتجديد يرجى التواصل معنا."]);
    }

    public function down(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn(['media_type', 'media_path', 'media_mime', 'media_name']));
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_muted_by');
            $table->dropColumn(['whatsapp_muted', 'whatsapp_muted_at']);
        });
    }
};

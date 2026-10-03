<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الأرقام المصرح لها بإرسال أوامر واتساب (الموظفون/المدير)
        Schema::create('whatsapp_authorized_users', function (Blueprint $table) {
            $table->id();
            $table->string('phone_normalized', 15)->unique();
            $table->string('name', 120)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // جلسات الحوار: تتبع حالة كل محادثة (الموظف يرسل أمر والنظام يطلب بياناته)
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('phone_normalized', 15)->index();
            $table->string('state', 40)->default('idle'); // idle | awaiting_* | done
            $table->jsonb('data')->nullable(); // بيانات مؤقتة أثناء الحوار
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('expires_at')->nullable(); // انتهاء الجلسة بعد مدة
            $table->timestampsTz();

            $table->unique('phone_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('whatsapp_authorized_users');
    }
};

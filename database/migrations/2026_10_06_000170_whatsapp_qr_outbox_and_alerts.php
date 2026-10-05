<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Numbers that receive the staff alerts (يجب التفعيل، دين ثانوي ينتهي خلال 24 ساعة…).
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->boolean('receives_alerts')->default(false);
        });

        // Which automatic message a template is used for (one template per event).
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('auto_event', 20)->nullable()->unique();
        });

        // Every message the system sends from the linked phone, queued and sent slowly by the
        // scheduler. The reference makes the same alert or reminder impossible to queue twice.
        Schema::create('whatsapp_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('to_phone', 20);
            $table->text('body');
            $table->string('kind', 30);
            $table->string('reference', 120)->nullable()->unique();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status', 10)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['status', 'id']);
            $table->index('sent_at');
        });
        DB::statement("ALTER TABLE whatsapp_outbox ADD CONSTRAINT whatsapp_outbox_status_check CHECK (status IN ('pending','sent','failed','skipped'))");

        // The starter reminder texts become the automatic messages, and the renewal message is added.
        foreach (['قرب نهاية الاشتراك' => 'expiring', 'انتهاء الاشتراك' => 'expired', 'تذكير بالمبلغ المتبقي' => 'debt'] as $title => $event) {
            DB::table('message_templates')->where('title', $title)->whereNull('auto_event')
                ->whereNotExists(fn ($q) => $q->from('message_templates as t')->where('t.auto_event', $event))
                ->limit(1)->update(['auto_event' => $event]);
        }
        if (DB::table('message_templates')->exists() && ! DB::table('message_templates')->where('auto_event', 'renewal')->exists()) {
            DB::table('message_templates')->insert([
                'icon' => '✅', 'title' => 'تم التجديد', 'auto_event' => 'renewal', 'sort_order' => 0, 'is_active' => true,
                'body' => "تحية طيبة {الاسم}،\nتم تجديد اشتراككم ({الفئة}) حتى {تاريخ_الانتهاء}.\nالمبلغ المطلوب: {المبلغ}.\nشكراً لكم.",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbox');
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('auto_event'));
        Schema::table('whatsapp_numbers', fn (Blueprint $table) => $table->dropColumn('receives_alerts'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payment methods: Qi Card and Mastercard become two methods, and the transaction number is
        // optional everywhere (kept and shown when entered, never a reason to refuse a payment).
        $now = now();
        DB::table('payment_methods')->where('code', 'qi_card')->update(['name_ar' => 'كي كارد', 'updated_at' => $now]);
        if (DB::table('payment_methods')->where('code', 'qi_card')->exists() && DB::table('payment_methods')->where('code', 'mastercard')->doesntExist()) {
            $qi = DB::table('payment_methods')->where('code', 'qi_card')->first();
            DB::table('payment_methods')->insert([
                'code' => 'mastercard', 'name_ar' => 'ماستر كارد', 'category' => 'card', 'requires_receiver' => false,
                'requires_reference' => false, 'money_account_id' => $qi->money_account_id, 'is_active' => true,
                'sort_order' => $qi->sort_order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('payment_methods')->update(['requires_reference' => false]);

        // Bulk messages chosen by a user, each with its recipients in the outbox.
        Schema::create('whatsapp_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->string('image_path', 255)->nullable();
            $table->string('image_mime', 60)->nullable();
            $table->integer('recipients')->default(0);
            $table->integer('skipped')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('whatsapp_outbox', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->foreignId('broadcast_id')->nullable()->constrained('whatsapp_broadcasts');
            $table->string('media_path', 255)->nullable();
            $table->string('media_mime', 60)->nullable();
            $table->index('payment_id');
            $table->index('broadcast_id');
        });

        // The message after a payment, editable in «قوالب الرسائل».
        if (DB::table('message_templates')->exists() && DB::table('message_templates')->where('auto_event', 'payment')->doesntExist()) {
            DB::table('message_templates')->insert([
                'icon' => '🧾', 'title' => 'تم استلام الدفعة', 'auto_event' => 'payment', 'sort_order' => 0, 'is_active' => true,
                'body' => "تم استلام دفعتك بنجاح.\nاسم المشترك: {الاسم}\nالمبلغ المستلم: {المبلغ_المستلم}\nطريقة الدفع: {طريقة_الدفع}\nالتاريخ: {تاريخ_الدفع}\nشكراً لك.",
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_outbox', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('broadcast_id');
            $table->dropColumn(['media_path', 'media_mime']);
        });
        Schema::dropIfExists('whatsapp_broadcasts');
    }
};

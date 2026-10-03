<?php

namespace Database\Seeders;

use App\Models\WhatsAppAuthorizedUser;
use App\Support\Arabic;
use Illuminate\Database\Seeder;

/**
 * يضيف الأرقام المصرح لها بإرسال أوامر واتساب.
 *
 * الاستخدام:
 *   php artisan db:seed --class=WhatsAppAuthorizedUserSeeder
 *
 * عدّل الأرقام أدناه حسب أرقام الموظفين/المدير.
 */
class WhatsAppAuthorizedUserSeeder extends Seeder
{
    public function run(): void
    {
        // أضف أرقام الموظفين المصرح لهم هنا (بصيغة عراقية)
        $phones = [
            '07701234567', // مثال: رقم المدير
        ];

        foreach ($phones as $phone) {
            $normalized = Arabic::phone($phone);
            if (strlen($normalized) < 10) {
                continue;
            }

            WhatsAppAuthorizedUser::updateOrCreate(
                ['phone_normalized' => $normalized],
                ['is_active' => true]
            );
        }

        $this->command?->info('تمت إضافة الأرقام المصرح لها.');
    }
}

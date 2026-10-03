<?php

namespace App\Services;

use App\Enums\ActivationKind;
use App\Enums\Settlement;
use App\Models\Branch;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\WhatsAppAuthorizedUser;
use App\Models\WhatsAppSession;
use App\Support\Arabic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * يفسّر أوامر واتساب ويدير الحوار خطوة بخطوة مع الموظف/المدير.
 *
 * الأوامر المدعومة:
 *   تسجيل        — بدء تسجيل مشترك جديد (يطلب الاسم ثم الهاتف ثم العنوان)
 *   حساب         — إضافة حساب (يوزر) لمشترك موجود (يطلب كود المشترك ثم بيانات الحساب)
 *   حالة         — عرض حالة مشترك (بكوده أو هاتفه)
 *   مساعدة/مساعدة — عرض قائمة الأوامر
 *   إلغاء        — إلغاء الحوار الجاري
 */
class WhatsAppCommandHandler
{
    private const SESSION_TTL_MINUTES = 30;

    public function __construct(
        private WhatsAppService $whatsapp,
        private SubscriberService $subscribers,
        private ActivationService $activations,
    ) {
    }

    /** نقطة الدخول: تُستدعى عند وصول رسالة واتساب */
    public function handle(string $from, string $text): void
    {
        $phone = Arabic::phone($from);

        // هل الرقم مصرح له؟
        $authorized = WhatsAppAuthorizedUser::where('phone_normalized', $phone)
            ->where('is_active', true)
            ->first();

        if (! $authorized) {
            $this->whatsapp->sendText($from, 'عذراً، هذا الرقم غير مصرح له بإرسال أوامر. تواصل مع مدير النظام.');

            return;
        }

        $text = trim($text);
        $session = WhatsAppSession::firstOrNew(['phone_normalized' => $phone]);

        // إنهاء الجلسات المنتهية
        if ($session->exists && $session->expires_at && $session->expires_at->isPast()) {
            $session->delete();
            $session = new WhatsAppSession(['phone_normalized' => $phone]);
        }

        // أمر إلغاء
        if ($this->isCommand($text, ['إلغاء', 'الغاء', 'cancel'])) {
            $session->delete();

            $this->whatsapp->sendText($from, 'تم إلغاء العملية الحالية.');

            return;
        }

        // أمر مساعدة
        if ($this->isCommand($text, ['مساعدة', 'مساعدة', 'help', 'اوامر', 'أوامر'])) {
            $this->sendHelp($from);

            return;
        }

        // إذا كانت هناك جلسة نشطة، نكمل الحوار
        if ($session->exists && $session->state !== 'idle') {
            $this->continueConversation($session, $from, $text);

            return;
        }

        // أمر جديد
        if ($this->isCommand($text, ['تسجيل', 'تسجيل جديد', 'اضافة مشترك', 'إضافة مشترك'])) {
            $this->startRegistration($session, $from, $authorized);

            return;
        }

        if ($this->isCommand($text, ['حساب', 'اضافة حساب', 'إضافة حساب', 'يوزر'])) {
            $this->startAccount($session, $from, $authorized);

            return;
        }

        if ($this->isCommand($text, ['حالة', 'استعلام', 'بحث'])) {
            $this->startStatus($session, $from);

            return;
        }

        // أمر تفعيل مباشر: «تفعيل محمد علي 30 يوم» أو «تفعيل حسين عباس 7 أيام»
        if ($this->isActivationCommand($text)) {
            $this->handleActivationCommand($from, $text, $authorized);

            return;
        }

        // أمر تفويض رقم جديد (للمدير فقط)
        if ($this->isCommand($text, ['تفويض', 'اضافة رقم', 'إضافة رقم', 'authorize'])) {
            $this->startAuthorize($session, $from, $authorized);

            return;
        }

        // رسالة غير معروفة
        $this->whatsapp->sendText($from, "لم أفهم الأمر. اكتب «مساعدة» لعرض الأوامر المتاحة.");
    }

    // ------------------------------------------------------------------
    // بدء العمليات
    // ------------------------------------------------------------------

    private function startRegistration(WhatsAppSession $session, string $from, WhatsAppAuthorizedUser $authorized): void
    {
        $session->state = 'awaiting_name';
        $session->data = [
            'branch_id' => $authorized->branch_id,
            'created_by' => $authorized->user_id,
        ];
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "أهلاً! لنبدأ تسجيل مشترك جديد.\n\nأرسل اسم المشترك الكامل:");
    }

    private function startAccount(WhatsAppSession $session, string $from, WhatsAppAuthorizedUser $authorized): void
    {
        $session->state = 'awaiting_subscriber_code';
        $session->data = [
            'branch_id' => $authorized->branch_id,
            'created_by' => $authorized->user_id,
        ];
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "لإضافة حساب (يوزر) لمشترك موجود.\n\nأرسل كود المشترك (مثل C-000123) أو رقم هاتفه:");
    }

    private function startStatus(WhatsAppSession $session, string $from): void
    {
        $session->state = 'awaiting_status_query';
        $session->data = [];
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "أرسل كود المشترك أو رقم هاتفه لعرض حالته:");
    }

    // ------------------------------------------------------------------
    // متابعة الحوار
    // ------------------------------------------------------------------

    private function continueConversation(WhatsAppSession $session, string $from, string $text): void
    {
        switch ($session->state) {
            case 'awaiting_name':
                $this->receiveName($session, $from, $text);
                break;

            case 'awaiting_phone':
                $this->receivePhone($session, $from, $text);
                break;

            case 'awaiting_address':
                $this->receiveAddress($session, $from, $text);
                break;

            case 'awaiting_subscriber_code':
                $this->receiveSubscriberCode($session, $from, $text);
                break;

            case 'awaiting_username':
                $this->receiveUsername($session, $from, $text);
                break;

            case 'awaiting_secret':
                $this->receiveSecret($session, $from, $text);
                break;

            case 'awaiting_serial':
                $this->receiveSerial($session, $from, $text);
                break;

            case 'awaiting_status_query':
                $this->receiveStatusQuery($session, $from, $text);
                break;

            case 'awaiting_authorize_phone':
                $this->receiveAuthorizePhone($session, $from, $text);
                break;

            case 'awaiting_authorize_name':
                $this->receiveAuthorizeName($session, $from, $text);
                break;

            default:
                $session->delete();
                $this->whatsapp->sendText($from, 'انتهت الجلسة. اكتب «تسجيل» أو «حساب» للبدء من جديد.');
        }
    }

    // --- تسجيل مشترك ---

    private function receiveName(WhatsAppSession $session, string $from, string $text): void
    {
        if (mb_strlen($text) < 3) {
            $this->whatsapp->sendText($from, 'الاسم قصير جداً. أرسل الاسم الكامل للمشترك:');

            return;
        }

        $data = $session->data ?? [];
        $data['full_name'] = $text;
        $session->data = $data;
        $session->state = 'awaiting_phone';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "تم حفظ الاسم: {$text}\n\nالآن أرسل رقم هاتف المشترك (مثال: 07701234567):");
    }

    private function receivePhone(WhatsAppSession $session, string $from, string $text): void
    {
        $phone = Arabic::phone($text);
        if (strlen($phone) < 10) {
            $this->whatsapp->sendText($from, 'رقم الهاتف غير صحيح. أرسل الرقم بصيغة عراقية (مثال: 07701234567):');

            return;
        }

        $data = $session->data ?? [];
        $data['phone'] = $text;
        $session->data = $data;
        $session->state = 'awaiting_address';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "تم حفظ الهاتف: {$text}\n\nأرسل عنوان المشترك (أو اكتب «لا يوجد»):");
    }

    private function receiveAddress(WhatsAppSession $session, string $from, string $text): void
    {
        $data = $session->data ?? [];
        $data['address'] = $this->isNo($text) ? null : $text;
        $session->data = $data;
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        // حفظ المشترك
        try {
            $subscriber = DB::transaction(function () use ($session, $data) {
                $branchId = $data['branch_id'] ?? Branch::first()?->id;

                return $this->subscribers->create([
                    'branch_id' => $branchId,
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'],
                    'address' => $data['address'] ?? null,
                    'status' => 'active',
                    'created_by' => $data['created_by'] ?? null,
                ]);
            });

            $session->delete();

            $this->whatsapp->sendText($from, "✅ تم تسجيل المشترك بنجاح!\n\n"
                ."الاسم: {$subscriber->full_name}\n"
                ."الكود: {$subscriber->code}\n"
                ."الهاتف: {$subscriber->phone}\n\n"
                ."لإضافة حساب (يوزر) لهذا المشترك، اكتب «حساب» ثم أرسل الكود {$subscriber->code}.");
        } catch (\Throwable $e) {
            Log::error('فشل تسجيل مشترك عبر واتساب', ['error' => $e->getMessage()]);
            $session->delete();
            $this->whatsapp->sendText($from, 'حدث خطأ أثناء الحفظ. حاول مرة أخرى لاحقاً، أو تواصل مع مدير النظام.');
        }
    }

    // --- إضافة حساب ---

    private function receiveSubscriberCode(WhatsAppSession $session, string $from, string $text): void
    {
        $subscriber = $this->findSubscriber($text);
        if (! $subscriber) {
            $this->whatsapp->sendText($from, 'لم أجد مشتركاً بهذا الكود أو الهاتف. تأكد من الكود (مثل C-000123) وحاول مجدداً:');

            return;
        }

        $data = $session->data ?? [];
        $data['subscriber_id'] = $subscriber->id;
        $session->data = $data;
        $session->state = 'awaiting_username';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "وجدت المشترك: {$subscriber->full_name} ({$subscriber->code})\n\nأرسل اسم المستخدم (اليوزر):");
    }

    private function receiveUsername(WhatsAppSession $session, string $from, string $text): void
    {
        if (mb_strlen($text) < 3) {
            $this->whatsapp->sendText($from, 'اسم المستخدم قصير جداً. أرسل اليوزر كاملاً:');

            return;
        }

        $data = $session->data ?? [];
        $data['username'] = $text;
        $session->data = $data;
        $session->state = 'awaiting_secret';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "تم حفظ اليوزر: {$text}\n\nأرسل كلمة السر (الباسورد):");
    }

    private function receiveSecret(WhatsAppSession $session, string $from, string $text): void
    {
        $data = $session->data ?? [];
        $data['secret'] = $text;
        $session->data = $data;
        $session->state = 'awaiting_serial';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "تم حفظ كلمة السر.\n\nأرسل الرقم التسلسلي (السيريال) أو اكتب «لا يوجد»:");
    }

    private function receiveSerial(WhatsAppSession $session, string $from, string $text): void
    {
        $data = $session->data ?? [];
        $data['serial_number'] = $this->isNo($text) ? null : $text;
        $session->data = $data;
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        // حفظ الحساب
        try {
            $subscriber = Subscriber::find($data['subscriber_id']);
            if (! $subscriber) {
                $session->delete();
                $this->whatsapp->sendText($from, 'لم أعد أجد المشترك. ابدأ من جديد بأمر «حساب».');

                return;
            }

            $createdBy = $data['created_by'] ?? null;

            $account = $this->subscribers->createAccount($subscriber, [
                'username' => $data['username'],
                'secret' => $data['secret'],
                'serial_number' => $data['serial_number'] ?? null,
                'created_by' => $createdBy,
            ]);

            $session->delete();

            $this->whatsapp->sendText($from, "✅ تم إضافة الحساب بنجاح!\n\n"
                ."المشترك: {$subscriber->full_name} ({$subscriber->code})\n"
                ."اليوزر: {$account->username}\n"
                ."السيريال: ".($account->serial_number ?: '—'));
        } catch (\Throwable $e) {
            Log::error('فشل إضافة حساب عبر واتساب', ['error' => $e->getMessage()]);
            $session->delete();
            $this->whatsapp->sendText($from, 'حدث خطأ أثناء الحفظ (ربما اليوزر مكرر). حاول مرة أخرى، أو تواصل مع مدير النظام.');
        }
    }

    // --- الاستعلام عن الحالة ---

    private function receiveStatusQuery(WhatsAppSession $session, string $from, string $text): void
    {
        $subscriber = $this->findSubscriber($text);
        if (! $subscriber) {
            $this->whatsapp->sendText($from, 'لم أجد مشتركاً بهذا الكود أو الهاتف.');

            return;
        }

        $accounts = $subscriber->accounts;
        $lines = ["المشترك: {$subscriber->full_name} ({$subscriber->code})", "الهاتف: {$subscriber->phone}"];

        if ($accounts->isEmpty()) {
            $lines[] = 'الحسابات: لا يوجد';
        } else {
            $lines[] = 'الحسابات:';
            foreach ($accounts as $account) {
                $lines[] = "  • {$account->username}".($account->serial_number ? " (سيريال: {$account->serial_number})" : '');
            }
        }

        $session->delete();
        $this->whatsapp->sendText($from, implode("\n", $lines));
    }

    // ------------------------------------------------------------------
    // أدوات مساعدة
    // ------------------------------------------------------------------

    /**
     * هل الرسالة أمر تفعيل؟ الصيغة: «تفعيل [الاسم] [30 يوم|7 أيام]»
     */
    private function isActivationCommand(string $text): bool
    {
        return preg_match('/^تفعيل\s+.+/u', trim($text)) === 1
            || preg_match('/^فعّل\s+.+/u', trim($text)) === 1;
    }

    /**
     * معالجة أمر التفعيل: يبحث عن المشترك بالاسم، ويحدد عدد الأيام من النص،
     * ثم يسجل ديناً (أولي لـ30 يوم، ثانوي لـ7 أيام).
     */
    private function handleActivationCommand(string $from, string $text, WhatsAppAuthorizedUser $authorized): void
    {
        // استخراج عدد الأيام من النص
        $days = $this->extractDays($text);
        if ($days === null) {
            $this->whatsapp->sendText($from, 'لم أفهم عدد الأيام. اكتب مثلاً: «تفعيل محمد علي 30 يوم» أو «تفعيل حسين عباس 7 أيام».');

            return;
        }

        // استخراج الاسم (كل ما بعد كلمة «تفعيل» وقبل عدد الأيام)
        $name = $this->extractName($text);
        if ($name === null || mb_strlen($name) < 2) {
            $this->whatsapp->sendText($from, 'لم أجد اسم المشترك. اكتب مثلاً: «تفعيل محمد علي 30 يوم».');

            return;
        }

        // البحث عن المشترك بالاسم
        $subscriber = $this->findSubscriber($name);
        if (! $subscriber) {
            $this->whatsapp->sendText($from, "لم أجد مشتركاً باسم «{$name}». تأكد من الاسم أو سجّله أولاً بأمر «تسجيل».");

            return;
        }

        // الحصول على أول حساب فعّال للمشترك
        $account = $subscriber->accounts()->where('status', 'active')->first();
        if (! $account) {
            $this->whatsapp->sendText($from, "المشترك «{$subscriber->full_name}» لا يملك حساباً فعّالاً. أضف له حساباً بأمر «حساب».");

            return;
        }

        // اختيار الخطة الافتراضية (الأساسية)
        $plan = ServicePlan::where('is_active', true)->orderBy('sort_order')->first();
        if (! $plan) {
            $this->whatsapp->sendText($from, 'لا توجد خطة خدمة مفعّلة. أضف خطة من اللوحة.');

            return;
        }

        // تحديد نوع التفعيل
        $kind = $days >= 30 ? ActivationKind::Full30 : ActivationKind::Partial7;
        $bucketLabel = $kind === ActivationKind::Full30 ? 'الأولي' : 'الثانوي';

        try {
            $activation = $this->activations->activate(
                account: $account,
                plan: $plan,
                kind: $kind,
                settlement: Settlement::Debt,
                notes: 'تفعيل عبر واتساب',
                createdBy: $authorized->user_id,
            );

            $this->whatsapp->sendText($from, "✅ تم تفعيل الاشتراك!\n\n"
                ."المشترك: {$subscriber->full_name} ({$subscriber->code})\n"
                ."اليوزر: {$account->username}\n"
                ."المدة: ".($kind === ActivationKind::Full30 ? '30 يوم' : '7 أيام')."\n"
                ."الدين: {$bucketLabel} (".number_format($activation->debt->original_amount)." د.ع)\n"
                ."رقم التفعيل: {$activation->number}");
        } catch (\Throwable $e) {
            Log::error('فشل تفعيل عبر واتساب', ['error' => $e->getMessage()]);
            $this->whatsapp->sendText($from, 'حدث خطأ أثناء التفعيل: '.$e->getMessage());
        }
    }

    /**
     * استخراج عدد الأيام من نص الأمر.
     * يدعم: «30 يوم»، «30»، «7 أيام»، «7»، «شهر»، «اسبوع».
     */
    private function extractDays(string $text): ?int
    {
        $t = Arabic::normalize($text);

        // «شهر» = 30 يوم
        if (preg_match('/(شهر|شهرين|ثلاثة اشهر|ثلاث اشهر)/u', $t)) {
            if (str_contains($t, 'شهرين')) {
                return 60;
            }
            if (str_contains($t, 'ثلاث')) {
                return 90;
            }

            return 30;
        }

        // «اسبوع» = 7 أيام
        if (preg_match('/(اسبوع|اسابيع)/u', $t)) {
            return 7;
        }

        // رقم + يوم/أيام
        if (preg_match('/(\d+)\s*(يوم|ايام|أيام|شهر)?/u', $t, $m)) {
            $num = (int) $m[1];
            if ($num >= 1 && $num <= 365) {
                return $num;
            }
        }

        return null;
    }

    /**
     * استخراج اسم المشترك من نص الأمر (كل ما بعد «تفعيل» وقبل عدد الأيام).
     */
    private function extractName(string $text): ?string
    {
        // إزالة كلمة «تفعيل» أو «فعّل» من البداية
        $rest = preg_replace('/^(تفعيل|فعّل)\s+/u', '', trim($text));
        if ($rest === null) {
            return null;
        }

        // إزالة جزء عدد الأيام من النهاية
        $rest = preg_replace('/\s*\d+\s*(يوم|ايام|أيام|شهر|شهرين|ثلاثة اشهر|ثلاث اشهر|اسبوع|اسابيع)?\s*$/u', '', $rest);

        return trim($rest) ?: null;
    }

    private function startAuthorize(WhatsAppSession $session, string $from, WhatsAppAuthorizedUser $authorized): void
    {
        // فقط المدير (أول رقم مضاف) يقدر يضيف أرقام أخرى
        $isFirst = WhatsAppAuthorizedUser::where('is_active', true)->orderBy('id')->first();
        if (! $isFirst || $isFirst->phone_normalized !== $authorized->phone_normalized) {
            $this->whatsapp->sendText($from, 'عذراً، فقط المدير يستطيع تفويض أرقام جديدة.');

            return;
        }

        $session->state = 'awaiting_authorize_phone';
        $session->data = [];
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "لتفويض رقم جديد.\n\nأرسل رقم هاتف الموظف (مثال: 07701234567):");
    }

    private function receiveAuthorizePhone(WhatsAppSession $session, string $from, string $text): void
    {
        $phone = Arabic::phone($text);
        if (strlen($phone) < 10) {
            $this->whatsapp->sendText($from, 'رقم الهاتف غير صحيح. أرسل الرقم بصيغة عراقية (مثال: 07701234567):');

            return;
        }

        $data = $session->data ?? [];
        $data['phone_normalized'] = $phone;
        $session->data = $data;
        $session->state = 'awaiting_authorize_name';
        $session->last_message_at = now();
        $session->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
        $session->save();

        $this->whatsapp->sendText($from, "تم حفظ الرقم: {$text}\n\nأرسل اسم الموظف:");
    }

    private function receiveAuthorizeName(WhatsAppSession $session, string $from, string $text): void
    {
        $data = $session->data ?? [];

        WhatsAppAuthorizedUser::updateOrCreate(
            ['phone_normalized' => $data['phone_normalized']],
            ['name' => $text, 'is_active' => true]
        );

        $session->delete();

        $this->whatsapp->sendText($from, "✅ تم تفويض الرقم {$data['phone_normalized']} للموظف {$text}.\n\n"
            .'يمكنه الآن إرسال أوامر «تسجيل» و«حساب» و«حالة».');
    }

    private function sendHelp(string $from): void
    {
        $this->whatsapp->sendText($from, "الأوامر المتاحة:\n\n"
            ."• «تسجيل» — تسجيل مشترك جديد\n"
            ."• «حساب» — إضافة حساب (يوزر) لمشترك\n"
            ."• «تفعيل [الاسم] 30 يوم» — تفعيل وتسجيل دين أولي\n"
            ."• «تفعيل [الاسم] 7 أيام» — تفعيل وتسجيل دين ثانوي\n"
            ."• «حالة» — عرض حالة مشترك\n"
            ."• «تفويض» — تفويض رقم موظف جديد (للمدير)\n"
            ."• «إلغاء» — إلغاء العملية الحالية\n"
            ."• «مساعدة» — عرض هذه القائمة");
    }

    private function findSubscriber(string $term): ?Subscriber
    {
        $term = trim($term);

        // بالكود
        if (preg_match('/^C-\d+$/i', $term)) {
            return Subscriber::where('code', strtoupper($term))->first();
        }

        // بالهاتف
        $phone = Arabic::phone($term);
        if (strlen($phone) >= 10) {
            return Subscriber::where('phone_normalized', $phone)->first();
        }

        // بالاسم
        return Subscriber::where('name_search', 'ilike', '%'.Arabic::normalize($term).'%')->first();
    }

    private function isCommand(string $text, array $commands): bool
    {
        $normalized = mb_strtolower(trim($text));

        foreach ($commands as $cmd) {
            if ($normalized === mb_strtolower($cmd)) {
                return true;
            }
        }

        return false;
    }

    private function isNo(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return in_array($t, ['لا', 'لا يوجد', 'لايوجد', 'لا شيء', 'none', 'no'], true);
    }
}

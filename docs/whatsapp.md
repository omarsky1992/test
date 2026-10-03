# تشغيل لوحة تحكم واتساب

تعمل اللوحة عبر **WhatsApp Cloud API** الرسمية من Meta. تحتاج رقم هاتف غير مسجَّل على تطبيق واتساب العادي (أو رقم Meta التجريبي للتجربة).

## 1. إنشاء التطبيق في Meta (مرة واحدة)

1. ادخل إلى <https://developers.facebook.com/apps> ← **Create app** ← نوع **Business** ← أضف منتج **WhatsApp**.
2. من **WhatsApp ← API Setup**:
   - انسخ **Phone number ID** ← هذا `WHATSAPP_PHONE_NUMBER_ID`.
   - أضف رقمك الحقيقي وسجّله (أو استخدم الرقم التجريبي).
3. **رمز دائم (Access token):** من **Business Settings ← Users ← System users** أنشئ مستخدم نظام بدور Admin، وأعطه التطبيق وحساب واتساب، ثم **Generate token** مع الصلاحيتين `whatsapp_business_messaging` و`whatsapp_business_management` ← هذا `WHATSAPP_ACCESS_TOKEN`. (رمز صفحة API Setup ينتهي بعد 24 ساعة، لا تستخدمه للتشغيل الدائم.)
4. **App secret:** من **App settings ← Basic ← App secret** ← هذا `WHATSAPP_APP_SECRET`.
5. **Verify token:** اختر أي كلمة طويلة عشوائية بنفسك ← هذه `WHATSAPP_VERIFY_TOKEN`.

## 2. المفاتيح على السيرفر

في `~/subs/deploy/.env` أضف (القيم من الخطوة 1 فقط، لا ترسلها لأحد):

```
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_APP_SECRET=...
WHATSAPP_VERIFY_TOKEN=...
ANTHROPIC_API_KEY=...        # من console.anthropic.com: لفهم الكلام الحر والمشتريات
OPENAI_API_KEY=...           # من platform.openai.com: لتحويل الرسائل الصوتية إلى نص
```

ثم: `bash ~/subs/deploy/update.sh` (أو `docker compose --env-file .env up -d --force-recreate app scheduler` داخل مجلد deploy).

- بدون `ANTHROPIC_API_KEY` تعمل الأوامر القصيرة الثابتة فقط (التفعيل، القبض، مسح الدين، الاستعلامات)، ولا تعمل المشتريات والكلام الحر.
- بدون `OPENAI_API_KEY` لا تعمل الرسائل الصوتية.

## 3. ربط الـ Webhook

1. في النظام: **واتساب ← إعدادات واتساب**، تأكد أن كل المفاتيح «مضبوط»، وانسخ **رابط الـ Webhook** (مثل `https://your-domain/whatsapp/webhook`).
2. في Meta: **WhatsApp ← Configuration ← Webhook ← Edit**: الصق الرابط في Callback URL، واكتب نفس `WHATSAPP_VERIFY_TOKEN`، ثم **Verify and save**.
3. في نفس الصفحة: **Webhook fields ← messages ← Subscribe**.

## 4. التشغيل

1. **واتساب ← الأرقام المصرح لها ← إضافة رقم**: الرقم، التسمية، والمستخدم الذي يعمل بصلاحياته.
2. **واتساب ← إعدادات واتساب**: فعّل «تشغيل لوحة تحكم واتساب».
3. أرسل من الرقم المصرح: «الديون الثانوية». يجب أن يصلك الرد خلال ثوانٍ، وتظهر الرسالة في **سجل عمليات واتساب**.

## ما الذي يُحفظ

- كل رسالة: الرقم، المستخدم، النص أو نص الصوت، ما فهمه النظام، النتيجة، البيانات التي تغيرت، الرد.
- كل تغيير مالي يمر بنفس خدمات البرنامج ويُسجَّل في **سجل العمليات** بمصدر `whatsapp` وباسم المستخدم المرتبط بالرقم.
- رسالة الرقم غير المصرح: يُحفظ الرقم والوقت فقط، ولا يُقرأ المحتوى ولا يُحمَّل الصوت.

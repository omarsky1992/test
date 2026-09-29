# المرحلة 2 — تصميم قاعدة البيانات (PostgreSQL)

## 2.0 اصطلاحات عامة

| البند | القاعدة | السبب |
|-------|---------|-------|
| المفتاح الأساسي | `id BIGINT GENERATED ALWAYS AS IDENTITY` | بسيط وسريع في الفهارس والعلاقات. لا يُعرض للمستخدم، والمعروض هو «الرقم» (`number`) |
| أرقام المستندات | `number VARCHAR(20) UNIQUE` مثل `R-2026-000123` | رقم مقروء، تسلسلي بلا فجوات (انظر `document_sequences`) |
| المبالغ | `BIGINT` بأصغر وحدة للعملة + `currency_code CHAR(3)` | IQD بلا كسور (`minor_unit = 0`)، فـ 35,000 تُخزَّن `35000`. لا Floating-point إطلاقاً. عند إضافة الدولار (`minor_unit = 2`) يُخزَّن 12.50$ كـ `1250` |
| الوقت | `TIMESTAMPTZ` | تخزين UTC، وعرض وحساب الأيام بتوقيت `Asia/Baghdad` |
| الحالة | `VARCHAR` + `CHECK (status IN (...))` | أوضح من ENUM في PostgreSQL وأسهل في التوسعة |
| التتبع | `created_at`, `created_by`, `updated_at`, `updated_by` | في كل جدول تشغيلي |
| الحذف | **لا حذف فعلي** للبيانات التشغيلية والمالية. المشترك والحساب يُؤرشفان بالحالة، والمستندات المالية تُلغى (`status = 'voided'`) | حفظ التاريخ ومتطلبات التدقيق |
| الإلغاء | `voided_at`, `voided_by`, `void_reason` + قيد عكسي | «الحذف» الذي طلبه المدير |
| الإضافات | `pg_trgm` (بحث جزئي)، `btree_gist` (منع تداخل الفترات) | — |

### تطبيع البحث العربي

عمود `name_search` يُحسب في التطبيق عند الحفظ: توحيد (أ إ آ ← ا)، (ة ← ه)، (ى ← ي)، حذف التشكيل والتطويل والمسافات الزائدة. عليه فهرس `GIN (name_search gin_trgm_ops)` حتى يجد البحث «احمد» عند كتابة «أحمد» أو جزء من الاسم.

**تطبيع الهاتف:** `phone_normalized` بصيغة `9647XXXXXXXXX`، فيجد البحث `0770…` و`+964770…` و`770…` جميعها.

---

## 2.1 الهوية والصلاحيات

### `branches` — الفروع
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| code | VARCHAR(20) | UNIQUE, NOT NULL |
| name | VARCHAR(100) | NOT NULL |
| is_active | BOOLEAN | DEFAULT true |
| created_at, updated_at | TIMESTAMPTZ | |

### `users` — الموظفون والمدراء
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| branch_id | BIGINT | FK → branches, NOT NULL |
| name | VARCHAR(120) | NOT NULL |
| username | CITEXT | UNIQUE, NOT NULL |
| phone | VARCHAR(20) | |
| password_hash | VARCHAR(255) | NOT NULL (Argon2id) |
| is_active | BOOLEAN | DEFAULT true |
| last_login_at | TIMESTAMPTZ | |
| password_changed_at | TIMESTAMPTZ | |
| created_at, updated_at | TIMESTAMPTZ | |

لا حذف للمستخدم، بل تعطيل (`is_active = false`)، لأن اسمه مرتبط بكل عملياته السابقة.

### الأدوار والصلاحيات (هيكل `spatie/laravel-permission`)
| الجدول | الحقول | الملاحظة |
|--------|--------|----------|
| `roles` | id, name (UNIQUE: `admin`, `employee`…), label_ar, is_system | أدوار جديدة تُضاف من الشاشة |
| `permissions` | id, name (UNIQUE مثل `debts.void`), module, label_ar | القائمة الكاملة في [05-permissions.md](05-permissions.md) |
| `role_has_permissions` | role_id, permission_id | PK مركب |
| `model_has_roles` | role_id, model_id (user) | PK مركب |
| `model_has_permissions` | permission_id, model_id (user) | صلاحية مباشرة لموظف معيّن («ومن أعطيه الصلاحية») |

---

## 2.2 المرجعيات والإعدادات

### `currencies`
| الحقل | النوع | القيود |
|-------|------|--------|
| code | CHAR(3) | PK (`IQD`) |
| name_ar | VARCHAR(50) | |
| minor_unit | SMALLINT | NOT NULL (IQD = 0) |
| symbol | VARCHAR(10) | `د.ع` |
| is_default | BOOLEAN | فهرس فريد جزئي: عملة افتراضية واحدة فقط |
| is_active | BOOLEAN | |

### `settings`
| الحقل | النوع | ملاحظة |
|-------|------|--------|
| key | VARCHAR(100) | PK |
| value | JSONB | |
| updated_by, updated_at | | |

**المفاتيح الأولية:** `activation.partial_days = 7`، `activation.full_days = 30`، `activation.extension_days = 23` (يُحسب = full − partial)، `accounts.max_per_subscriber_warning = 2`، `session.idle_minutes = 30`، `timezone = Asia/Baghdad`.

### `document_sequences` — ترقيم بلا فجوات
| الحقل | النوع | القيود |
|-------|------|--------|
| doc_type | VARCHAR(20) | `receipt`, `debt`, `activation`, `transfer`, `expense`, `sale`, `fund_transfer`, `settlement` |
| year | SMALLINT | |
| last_value | BIGINT | NOT NULL |
| | | PK (doc_type, year) |

يُقفل الصف (`FOR UPDATE`) ويُزاد **داخل نفس معاملة** إنشاء المستند. إذا فشلت المعاملة يُلغى الرقم معها، فلا تحدث فجوات. البادئات: `R` سند قبض، `D` دين، `A` تفعيل، `T` مناقلة، `E` مصروف، `S` مبيعات، `F` تحويل بين الصناديق، `K` راجع الشركة.

---

## 2.3 المشتركون والحسابات

### `subscribers` — المشترك (الشخص)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| branch_id | BIGINT | FK → branches, NOT NULL |
| code | VARCHAR(30) | UNIQUE, NOT NULL. رقم داخلي `C-000123` |
| full_name | VARCHAR(150) | NOT NULL |
| name_search | VARCHAR(150) | NOT NULL، مطبَّع |
| phone | VARCHAR(20) | NOT NULL: **رقم الهاتف المسجل في الشركة**، وهو «رقم المشترك» (Q6) |
| phone_normalized | VARCHAR(15) | NOT NULL |
| alt_phone | VARCHAR(20) | |
| address | TEXT | |
| notes | TEXT | |
| status | VARCHAR(20) | CHECK IN (`active`, `inactive`, `blocked`, `archived`) |
| created_by | BIGINT | FK → users |
| created_at, updated_at, updated_by | | |

**الفهارس:** `GIN(name_search gin_trgm_ops)`، `(phone_normalized)`، `(branch_id, status)`.
**لماذا لا يكون الهاتف UNIQUE رغم أنه رقم المشترك؟** قد يسجّل أفراد عائلة بنفس الرقم في الشركة. عند إدخال رقم موجود، يقترح النظام **إضافة حساب جديد للمشترك الموجود** بدل إنشاء مشترك مكرر، ويسمح بالتكرار بتأكيد صريح. المشترك له أيضاً `code` داخلي ثابت لأن الهاتف قد يتغير.

### `accounts` — الحساب (الاشتراك / اليوزر على بيت)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| subscriber_id | BIGINT | FK → subscribers, NOT NULL |
| branch_id | BIGINT | FK → branches, NOT NULL |
| username | CITEXT | **UNIQUE, NOT NULL**: يوزر الاشتراك على موقع الشركة |
| secret_encrypted | TEXT | باسورد الاشتراك مشفّر (AES-256) |
| serial_number | VARCHAR(60) | UNIQUE NULL: سيريال الجهاز أو ONT |
| phone | VARCHAR(20) | هاتف الحساب (قد يختلف عن هاتف المشترك) |
| phone_normalized | VARCHAR(15) | |
| fat_code | VARCHAR(50) | الفات |
| pole_number | VARCHAR(50) | رقم العامود |
| location_label | VARCHAR(150) | مثال: «البيت الثاني – حي الجامعة» |
| address | TEXT | |
| current_plan_id | BIGINT | FK → service_plans NULL (آخر فئة مفعّلة، للعرض) |
| service_ends_at | TIMESTAMPTZ | **مخزَّن للبحث السريع فقط**، والمصدر الحقيقي `activation_periods` |
| status | VARCHAR(20) | CHECK IN (`active`, `suspended`, `closed`) |
| notes | TEXT | |
| created_by, created_at, updated_at, updated_by | | |

**الفهارس:** `(subscriber_id)`، `GIN(username gin_trgm_ops)`، `(phone_normalized)`، `(fat_code)`، `(pole_number)`، `(service_ends_at)` لقائمة «تنتهي قريباً».
**لماذا جدول مستقل؟** العلاقة 1 ← N دون حد في قاعدة البيانات. الحد (2) تحذير في الإعدادات وليس قيداً، حسب إجابة السؤال 2.
**الـ External ID للمزامنة** لا يُخزَّن هنا، بل في `external_links`، حتى لا ترتبط الجداول الأساسية بموقع الشركة.

### `follow_ups` — سجل المتابعة والاتصال (Q1)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| account_id | BIGINT | FK → accounts, NOT NULL |
| subscriber_id | BIGINT | FK, NOT NULL |
| activation_id | BIGINT | FK NULL (تفعيل الـ7 أيام المعني) |
| debt_id | BIGINT | FK NULL |
| channel | VARCHAR(12) | CHECK IN (`call`, `whatsapp`, `visit`, `sms`) |
| outcome | VARCHAR(20) | CHECK IN (`no_answer`, `promised_to_pay`, `will_pay_today`, `refused`, `wrong_number`, `other`) |
| promised_at | TIMESTAMPTZ | NULL: موعد الدفع الذي وعد به |
| next_follow_up_at | TIMESTAMPTZ | NULL: متى نتصل مرة أخرى |
| note | TEXT | |
| created_by, created_at | | للإضافة فقط |

**الفهارس:** `(account_id, created_at DESC)`، `(next_follow_up_at)`.

---

## 2.4 الفئات والعروض

### `service_plans` — الفئات (الكارتات)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| code | VARCHAR(30) | UNIQUE (`basic`, `plus`, `turbo`, `pro_max`) |
| name_ar | VARCHAR(60) | أساسي / بلس / تيربو / برو ماكس |
| price | BIGINT | NOT NULL, CHECK > 0 (35000 / 45000 / 65000 / 100000) |
| currency_code | CHAR(3) | FK → currencies |
| duration_days | SMALLINT | DEFAULT 30 |
| is_active | BOOLEAN | |
| sort_order | SMALLINT | |

تغيير السعر لا يؤثر على التفعيلات السابقة، لأن كل تفعيل يحتفظ بنسخة من السعر (Snapshot). والتغيير نفسه يُسجَّل في التدقيق.

### `promotions` — العروض
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| name | VARCHAR(120) | NOT NULL |
| audience | VARCHAR(20) | CHECK IN (`new`, `existing`, `all`) |
| plan_id | BIGINT | FK → service_plans NULL (NULL = كل الفئات) |
| discount_type | VARCHAR(20) | CHECK IN (`fixed_price`, `amount_off`, `percent_off`) |
| discount_value | BIGINT | CHECK > 0. النسبة ≤ 100 |
| starts_at, ends_at | TIMESTAMPTZ | CHECK ends_at > starts_at |
| funded_by | VARCHAR(10) | CHECK IN (`company`, `agent`): من يتحمّل الخصم (Q14) |
| is_active | BOOLEAN | |
| created_by, created_at | | |

**الفهرس:** `(is_active, starts_at, ends_at)`.

---

## 2.5 التفعيلات

### `activations` — عملية التفعيل
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`A-2026-000001`) |
| account_id | BIGINT | FK → accounts, NOT NULL |
| subscriber_id | BIGINT | FK → subscribers, NOT NULL (نسخة لتسريع التقارير) |
| branch_id | BIGINT | FK → branches |
| plan_id | BIGINT | FK → service_plans, NOT NULL |
| promotion_id | BIGINT | FK → promotions NULL |
| kind | VARCHAR(10) | CHECK IN (`partial_7`, `full_30`) |
| list_price | BIGINT | سعر الفئة وقت التفعيل |
| discount_amount | BIGINT | DEFAULT 0 |
| final_price | BIGINT | CHECK final_price = list_price − discount_amount AND ≥ 0 |
| currency_code | CHAR(3) | |
| settlement | VARCHAR(10) | CHECK IN (`debt`, `paid`, `credit`) |
| starts_at | TIMESTAMPTZ | بداية أول فترة (نسخة للعرض) |
| ends_at | TIMESTAMPTZ | نهاية آخر فترة غير ملغاة (نسخة للعرض) |
| completion_status | VARCHAR(15) | CHECK IN (`not_required`, `pending`, `completed`) |
| completed_at | TIMESTAMPTZ | |
| completed_via | VARCHAR(10) | CHECK IN (`payment`, `transfer`) NULL |
| start_overridden | BOOLEAN | DEFAULT false: هل عدّل الموظف وقت البداية |
| external_ref | VARCHAR(100) | مرجع العملية على موقع الشركة إن وُجد |
| status | VARCHAR(10) | CHECK IN (`posted`, `voided`) |
| voided_at, voided_by, void_reason | | (Q7) |
| notes | TEXT | |
| created_by, created_at | | `created_at` وقت إدخال السجل، ويختلف عن `starts_at` |

**قيد:** `kind = 'partial_7'` ⇒ `settlement = 'debt'` و`completion_status IN ('pending','completed')`.
**الفهارس:** `(account_id, starts_at DESC)`، `(completion_status) WHERE completion_status = 'pending'` (قائمة الـ7 أيام)، `(created_at)`، `(created_by, created_at)`، `(plan_id)`.

> **الحالة الزمنية لا تُخزَّن** (مجدول / فعّال / منتهي)، بل تُحسب من الفترات ووقت الآن. تخزينها يؤدي إلى حالات قديمة خاطئة إذا لم تعمل مهمة التحديث الدورية.

### `activation_periods` — فترات الخدمة الفعلية
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| activation_id | BIGINT | FK → activations, NOT NULL |
| account_id | BIGINT | FK → accounts, NOT NULL (نسخة لقيد التداخل) |
| period_type | VARCHAR(15) | CHECK IN (`initial_7`, `initial_30`, `extension_23`) |
| days | SMALLINT | NOT NULL |
| starts_at | TIMESTAMPTZ | NOT NULL |
| ends_at | TIMESTAMPTZ | NOT NULL, CHECK ends_at = starts_at + days × 1 day |
| source_type | VARCHAR(20) | `activation`, `payment`, `transfer` |
| source_id | BIGINT | المستند الذي أنشأ الفترة |
| is_void | BOOLEAN | DEFAULT false |
| created_by, created_at | | |

**القيد الأهم (منع التداخل):**
```sql
EXCLUDE USING gist (account_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
  WHERE (NOT is_void)
```
هذا يمنع **على مستوى قاعدة البيانات** وجود فترتين متداخلتين لنفس الحساب، مهما كان الخطأ في الكود أو في الإدخال.
**لماذا جدول فترات؟** تفعيل 7 أيام ثم +23 يوماً يعطي فترتين بتاريخين مختلفين ومنفّذين مختلفين. حفظهما منفصلتين يحفظ التاريخ الكامل: متى وأُضيفت بواسطة ماذا (قبض أو مناقلة) ومن نفّذها.

---

## 2.6 المالية

### `ledger_accounts` — دليل الحسابات المحاسبي (داخلي، لا يراه الموظف)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| code | VARCHAR(40) | UNIQUE |
| name_ar | VARCHAR(100) | |
| type | VARCHAR(10) | CHECK IN (`asset`, `liability`, `equity`, `revenue`, `expense`) |
| branch_id | BIGINT | FK NULL |
| is_system | BOOLEAN | |

**البيانات الأولية:**

| code | الاسم | النوع |
|------|-------|-------|
| `AR_SECONDARY` | الديون الثانوية | asset |
| `AR_PRIMARY` | الديون الأولية | asset |
| `CUSTOMER_CREDIT` | دفعات مقدمة (أرصدة دائنة للمشتركين) | liability |
| `CASH:<branch>` | قاصة الفرع / «الزون» (صندوق الشراء والتفعيل) | asset |
| `COMPANY_BALANCE` | رصيد الوكيل لدى الشركة (مدفوع مسبقاً، Q11) | asset |
| `WALLET:<id>` | محفظة إلكترونية لكل مستلم | asset |
| `REV_COMMISSION` | الراجع من الشركة | revenue |
| `REV_OTHER` | إيرادات أخرى (أجور تنصيب في دين يدوي…) | revenue |
| `REV_DEVICE_SALES` | إيراد مبيعات الأجهزة | revenue |
| `EXP_GENERAL` | المصروفات (تفصيلها بالفئة في جدول `expenses`) | expense |
| `EXP_PROMO_DISCOUNT` | خصومات العروض التي يتحملها الوكيل | expense |
| `DISTRIBUTIONS` | توزيعات الراجع (سحوبات شركاء، رواتب) | equity |
| `OPENING_EQUITY` | الرصيد الابتدائي / رأس المال | equity |

### `money_accounts` — الصناديق والمحافظ
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| branch_id | BIGINT | FK |
| kind | VARCHAR(12) | CHECK IN (`cash`, `electronic`, `company`) |
| name | VARCHAR(100) | «الزون – قاصة الفرع الرئيسي»، «زين كاش – أحمد»، «رصيد الشركة» |
| holder_name | VARCHAR(100) | اسم المستلم |
| ledger_account_id | BIGINT | FK → ledger_accounts, UNIQUE |
| is_active | BOOLEAN | |

### `financial_transactions` — رأس القيد (غير قابل للتعديل)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| txn_type | VARCHAR(30) | `activation_charge`, `manual_debt`, `opening_debt`, `payment`, `credit_application`, `debt_transfer`, `expense`, `device_sale`, `fund_transfer`, `company_settlement`, `distribution`, `opening_balance`, `reversal` |
| occurred_at | TIMESTAMPTZ | وقت العملية المالية |
| branch_id | BIGINT | FK |
| source_type, source_id | VARCHAR, BIGINT | المستند المصدر (دين، سند، مناقلة…) |
| reverses_txn_id | BIGINT | FK → financial_transactions, **UNIQUE** NULL: لا يُعكس القيد مرتين |
| memo | TEXT | |
| idempotency_key | UUID | UNIQUE |
| created_by, created_at | | |

### `ledger_entries` — سطور القيد (Append-only)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| txn_id | BIGINT | FK → financial_transactions, NOT NULL |
| ledger_account_id | BIGINT | FK → ledger_accounts, NOT NULL |
| debit | BIGINT | DEFAULT 0, CHECK ≥ 0 |
| credit | BIGINT | DEFAULT 0, CHECK ≥ 0 |
| currency_code | CHAR(3) | |
| occurred_at | TIMESTAMPTZ | نسخة من رأس القيد للتقارير |
| subscriber_id | BIGINT | FK NULL (دفتر أستاذ مساعد) |
| account_id | BIGINT | FK NULL |
| debt_id | BIGINT | FK NULL |

**القيود:**
- `CHECK ((debit = 0) <> (credit = 0))`: السطر مدين أو دائن، لا الاثنان ولا الصفر.
- **Constraint trigger مؤجّل (DEFERRABLE):** مجموع المدين = مجموع الدائن لكل `txn_id` عند نهاية المعاملة.
- **Trigger** يرفض أي `UPDATE` أو `DELETE` على `ledger_entries` و`financial_transactions`.

**الفهارس:** `(ledger_account_id, occurred_at)`، `(debt_id)`، `(account_id, occurred_at)`، `(subscriber_id, occurred_at)`.

**لماذا قيد مزدوج؟** طُلب في الـPRD «إجمالي الديون الثانوية» و«الأولية» و«الإيرادات اليومية» و«الرصيد الابتدائي + الفرق». هذه كلها أرصدة حسابات في الدفتر، وتُحسب بجمع بسيط دائماً صحيح، دون أعمدة مجاميع قابلة للخطأ. والقيد المتوازن يمنع ظهور أو اختفاء مال من العدم.

### `debts` — الديون
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`D-2026-000001`) |
| account_id | BIGINT | FK → accounts, **NOT NULL** (الدين يرتبط بالحساب) |
| subscriber_id | BIGINT | FK → subscribers, NOT NULL (نسخة) |
| branch_id | BIGINT | FK |
| source | VARCHAR(15) | CHECK IN (`activation`, `manual`, `opening`, `device_sale`) |
| activation_id | BIGINT | FK → activations, UNIQUE NULL |
| device_sale_id | BIGINT | FK → device_sales NULL |
| bucket | VARCHAR(10) | CHECK IN (`secondary`, `primary`) |
| original_amount | BIGINT | CHECK > 0 |
| paid_amount | BIGINT | DEFAULT 0 **(قيمة محسوبة مخزّنة)** |
| balance | BIGINT | **(قيمة محسوبة مخزّنة)** |
| currency_code | CHAR(3) | |
| debt_date | TIMESTAMPTZ | NOT NULL |
| due_date | DATE | NULL |
| status | VARCHAR(10) | CHECK IN (`open`, `partial`, `paid`, `voided`) |
| voided_at, voided_by, void_reason | | |
| notes | TEXT | |
| created_by, created_at, updated_at | | |

**القيود:** `CHECK (paid_amount BETWEEN 0 AND original_amount)`، `CHECK (balance = original_amount - paid_amount)`، `CHECK (status <> 'voided' OR void_reason IS NOT NULL)`.
**الفهارس:** `(account_id, status)`، `(bucket, status) WHERE status IN ('open','partial')`، `(debt_date)`، `(created_by, created_at)`.

> **`paid_amount` و`balance` نسخة مخزّنة (Cache)** تُحدَّث فقط من خدمة المالية داخل نفس المعاملة، للسرعة في القوائم. **المصدر الحقيقي هو الدفتر.** مهمة ليلية تطابق القيمتين وتنبّه المدير عند أي فرق.

### `payments` — القبض (كل سطر = سند قبض واحد)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| receipt_number | VARCHAR(20) | UNIQUE (`R-2026-000001`) |
| subscriber_id | BIGINT | FK, NOT NULL |
| account_id | BIGINT | FK, NOT NULL |
| branch_id | BIGINT | FK |
| payment_type | VARCHAR(15) | CHECK IN (`debt_payment`, `advance`) |
| method | VARCHAR(12) | CHECK IN (`cash`, `electronic`) |
| money_account_id | BIGINT | FK → money_accounts, NOT NULL |
| receiver_name | VARCHAR(100) | إلزامي إذا `method = 'electronic'` (CHECK) |
| external_reference | VARCHAR(100) | رقم الحوالة الإلكترونية |
| amount | BIGINT | CHECK > 0 |
| currency_code | CHAR(3) | |
| received_at | TIMESTAMPTZ | NOT NULL |
| status | VARCHAR(10) | CHECK IN (`posted`, `voided`) |
| voided_at, voided_by, void_reason | | |
| txn_id | BIGINT | FK → financial_transactions |
| idempotency_key | UUID | UNIQUE |
| notes | TEXT | |
| created_by, created_at | | |

**الفهارس:** `(received_at)`، `(created_by, received_at)`، `(account_id, received_at)`، `(subscriber_id)`، `(money_account_id, received_at)`.

### `payment_allocations` — توزيع القبض على الديون
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| payment_id | BIGINT | FK → payments, NOT NULL |
| debt_id | BIGINT | FK → debts, NOT NULL |
| amount | BIGINT | CHECK > 0 |
| txn_id | BIGINT | FK (قيد القبض، أو قيد تطبيق الرصيد المقدم لاحقاً) |
| is_reversed | BOOLEAN | DEFAULT false |
| created_by, created_at | | |

**القاعدة:** مجموع التخصيصات ≤ مبلغ الدفعة، ويُتحقق منه في الخدمة مع قفل الدفعة. الباقي رصيد دائن (دفع مقدم).
**لماذا جدول وسيط؟** دفعة واحدة قد تسدد عدة ديون، والدين قد يُسدَّد بعدة دفعات (علاقة N:M).

### `debt_transfers` — المناقلات
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`T-2026-000001`) |
| debt_id | BIGINT | FK → debts, NOT NULL |
| account_id | BIGINT | FK, NOT NULL |
| subscriber_id | BIGINT | FK, NOT NULL |
| activation_id | BIGINT | FK NULL |
| from_bucket | VARCHAR(10) | `secondary` |
| to_bucket | VARCHAR(10) | `primary`, CHECK from_bucket <> to_bucket |
| amount | BIGINT | CHECK > 0 (الرصيد المتبقي وقت النقل) |
| days_added | SMALLINT | 23 أو 0 (إذا كان التفعيل مكتملاً مسبقاً) |
| period_id | BIGINT | FK → activation_periods NULL |
| reason | TEXT | |
| performed_at | TIMESTAMPTZ | NOT NULL |
| performed_by | BIGINT | FK → users |
| status | VARCHAR(10) | `posted`, `voided` |
| voided_at, voided_by, void_reason | | |
| txn_id | BIGINT | FK |

**قابلية التوسع:** يمكن لاحقاً إضافة `from_account_id` و`to_account_id` لنقل دين بين حسابين (بيتين) دون كسر أي شيء.

### `expense_categories` / `expenses` — المصروفات
| `expense_categories` | id, name_ar (راوتر، أجهزة، رصيد شركة، إيجار…), is_active |
|---|---|

| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`E-…`) |
| branch_id | BIGINT | FK |
| category_id | BIGINT | FK → expense_categories |
| description | VARCHAR(255) | NOT NULL |
| amount | BIGINT | CHECK > 0 |
| currency_code | CHAR(3) | |
| money_account_id | BIGINT | FK: من أي صندوق دُفع |
| paid_to | VARCHAR(120) | |
| spent_at | TIMESTAMPTZ | |
| status, voided_* | | |
| txn_id | BIGINT | FK |
| notes, created_by, created_at | | |

### `device_sales` — المبيعات
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`S-…`) |
| branch_id | BIGINT | FK |
| subscriber_id | BIGINT | FK NULL (قد يكون المشتري غير مشترك) |
| account_id | BIGINT | FK NULL |
| buyer_name | VARCHAR(120) | إذا لم يكن مشتركاً |
| item_type_id | BIGINT | FK → device_types |
| description | VARCHAR(255) | |
| serial_number | VARCHAR(60) | |
| quantity | INT | CHECK > 0, DEFAULT 1 |
| unit_price | BIGINT | CHECK ≥ 0 |
| total_amount | BIGINT | CHECK = quantity × unit_price |
| cost_amount | BIGINT | NULL: كلفة الشراء لحساب الربح |
| purchase_expense_id | BIGINT | FK → expenses NULL: ربط البيع بمصروف الشراء |
| settlement | VARCHAR(10) | CHECK IN (`paid`, `debt`) |
| money_account_id | BIGINT | FK NULL (إذا `paid`) |
| sold_at | TIMESTAMPTZ | |
| status, voided_* | | |
| txn_id | BIGINT | FK |
| notes, created_by, created_at | | |

**الربح** = `total_amount − cost_amount`، ويدخل الرصيد عبر حسابات الإيراد والمصروف. التفاصيل في منطق التقارير.

### `fund_transfers` — التحويل بين الصناديق
يشمل **شحن رصيد الشركة** من القاصة، ونقل مال من محفظة إلكترونية إلى القاصة.

| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`F-…`) |
| from_money_account_id | BIGINT | FK → money_accounts, NOT NULL |
| to_money_account_id | BIGINT | FK → money_accounts, NOT NULL, CHECK ≠ from |
| amount | BIGINT | CHECK > 0 |
| currency_code | CHAR(3) | |
| transferred_at | TIMESTAMPTZ | |
| reference | VARCHAR(100) | رقم عملية الشحن إن وُجد |
| status, voided_* | | |
| txn_id | BIGINT | FK |
| notes, created_by, created_at | | |

### `company_settlements` — الراجع من الشركة (Q12)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| number | VARCHAR(20) | UNIQUE (`K-…`) |
| period_from, period_to | DATE | الفترة التي يغطيها الراجع |
| amount | BIGINT | CHECK > 0 |
| currency_code | CHAR(3) | |
| received_into_money_account_id | BIGINT | FK: القاصة، أو رصيد الشركة إذا أضافته الشركة لرصيدكم |
| received_at | TIMESTAMPTZ | |
| activations_count | INT | عدد التفعيلات في الفترة (محسوب للعرض والمقارنة) |
| status, voided_* | | |
| txn_id | BIGINT | FK |
| notes, created_by, created_at | | |

### `settlement_distributions` — تقسيم الراجع (Q13)
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| settlement_id | BIGINT | FK → company_settlements, NOT NULL |
| kind | VARCHAR(15) | CHECK IN (`zone_fund`, `partner`, `salary`, `other`) |
| beneficiary | VARCHAR(120) | اسم الشريك أو الموظف (لغير `zone_fund`) |
| amount | BIGINT | CHECK > 0 |
| from_money_account_id | BIGINT | FK: الصندوق الذي خرج منه المبلغ |
| to_money_account_id | BIGINT | FK NULL: صندوق «الزون» إذا `zone_fund` |
| txn_id | BIGINT | FK |
| created_by, created_at | | |

**القاعدة:** Σ التوزيعات ≤ مبلغ الراجع، ويظهر المتبقي غير الموزع في شاشة الراجع.

---

## 2.7 الأجهزة

### `device_types`
id, key (UNIQUE: `router`, `ont`, `repeater`, `cable`, `other`), name_ar, is_active

### `devices`
| الحقل | النوع | القيود |
|-------|------|--------|
| id | BIGINT | PK |
| subscriber_id | BIGINT | FK, NOT NULL |
| account_id | BIGINT | FK NULL (الجهاز مرتبط ببيت معيّن غالباً) |
| device_type_id | BIGINT | FK → device_types |
| brand, model | VARCHAR(60) | |
| serial_number | VARCHAR(60) | فهرس (غير فريد: قد يُعاد تسجيل جهاز مستبدل) |
| mac_address | VARCHAR(17) | |
| ownership | VARCHAR(15) | CHECK IN (`subscriber`, `company_loan`, `sold`) |
| device_sale_id | BIGINT | FK NULL |
| status | VARCHAR(15) | CHECK IN (`active`, `faulty`, `replaced`, `returned`) |
| installed_at | TIMESTAMPTZ | |
| attributes | JSONB | حقول مرنة للتوسع (منفذ الفات، قوة الإشارة…) |
| notes | TEXT | |
| created_by, created_at, updated_at | | |

### `device_notes` — سجل الملاحظات الفنية
id, device_id (FK), note (TEXT, NOT NULL), created_by, created_at. للإضافة فقط.

---

## 2.8 سجل التدقيق

### `audit_logs` (Append-only)
| الحقل | النوع | ملاحظة |
|-------|------|--------|
| id | BIGINT | PK |
| occurred_at | TIMESTAMPTZ | |
| user_id | BIGINT | FK NULL (NULL = النظام أو المزامنة) |
| action | VARCHAR(60) | `subscriber.updated`, `debt.voided`, `account.secret_viewed`… |
| entity_type | VARCHAR(40) | |
| entity_id | BIGINT | |
| subscriber_id | BIGINT | NULL: لعرض «الخط الزمني» للمشترك |
| old_values | JSONB | الحقول المتغيرة فقط |
| new_values | JSONB | |
| reason | TEXT | إلزامي للإلغاء وتعديل التاريخ |
| source | VARCHAR(10) | `web`, `api`, `sync`, `system` |
| sync_run_id | BIGINT | FK NULL |
| ip_address | INET | |
| user_agent | VARCHAR(255) | |
| request_id | UUID | يربط كل ما حدث في طلب واحد |

**الفهارس:** `(entity_type, entity_id)`، `(user_id, occurred_at)`، `(occurred_at)`، `(action)`، `(subscriber_id, occurred_at)`.
**الحماية:** Trigger يمنع التعديل والحذف. الباسورد يُستبدل بـ `***` قبل التسجيل. التقسيم الشهري (Partitioning) يُفعَّل عند كبر الحجم.

---

## 2.9 التكامل والمزامنة
(الشرح الكامل في [07-integration.md](07-integration.md))

| الجدول | الحقول الرئيسية | القيود |
|--------|-----------------|--------|
| `integration_sources` | id, key (UNIQUE), name, adapter (`excel_import`, `csv_import`, `paste`, `api`, `database`, `browser_export`), config_encrypted (JSONB), is_active, last_success_at | |
| `sync_runs` | id, source_id, mode (`full`, `incremental`), status (`pending`, `staging`, `review`, `applying`, `applied`, `failed`, `partial`), file_name, file_hash, stats (JSONB), error_message, retry_of_run_id, triggered_by, started_at, finished_at | UNIQUE (source_id, file_hash): منع استيراد نفس الملف مرتين عن طريق الخطأ |
| `sync_items` | id, run_id, entity_type, external_id, raw_payload (JSONB), normalized (JSONB), payload_hash, match_status (`new`, `matched`, `ambiguous`), action (`create`, `update`, `unchanged`, `missing`, `conflict`, `error`, `skipped`), target_type, target_id, error, attempts, applied_at | INDEX (run_id, action)، UNIQUE (run_id, entity_type, external_id) |
| `external_links` | id, source_id, entity_type, entity_id, external_id, last_payload_hash, last_synced_at, last_seen_run_id, missing_since | UNIQUE (source_id, entity_type, external_id)، UNIQUE (source_id, entity_type, entity_id) |
| `sync_conflicts` | id, sync_item_id, entity_type, entity_id, field, local_value, external_value, resolution (`pending`, `keep_local`, `take_external`), resolved_by, resolved_at | INDEX (resolution) |
| `sync_field_policies` | id, source_id, entity_type, field, owner (`external`, `local`, `manual`) | UNIQUE (source_id, entity_type, field) |

---

## 2.10 أمثلة القيود المحاسبية

| العملية | مدين | دائن | ملاحظة |
|---------|------|------|--------|
| رصيد ابتدائي للقاصة | `CASH` | `OPENING_EQUITY` | مرة واحدة |
| شحن رصيد الشركة | `COMPANY_BALANCE` | `CASH` / `WALLET` | `fund_transfer` |
| تفعيل 7 أيام (35,000) | `AR_SECONDARY` 35,000 | `COMPANY_BALANCE` 35,000 | **ليس إيراداً**: الشركة خصمت السعر من رصيدكم، والمشترك مدين به |
| تفعيل 30 يوم آجل | `AR_PRIMARY` | `COMPANY_BALANCE` | |
| تفعيل 30 يوم مدفوع | قيدان في نفس المعاملة: (1) `AR_PRIMARY` / `COMPANY_BALANCE`، ثم (2) `CASH` أو `WALLET` / `AR_PRIMARY` | | كل تفعيل له دين، وكل قبض له سند |
| عرض يتحمّله الوكيل (سعر 35,000، المشترك يدفع 30,000) | `AR_*` 30,000 + `EXP_PROMO_DISCOUNT` 5,000 | `COMPANY_BALANCE` 35,000 | عرض الشركة: السطران بالسعر المخفض فقط |
| قبض لتسديد دين | `CASH` / `WALLET` | `AR_SECONDARY` أو `AR_PRIMARY` | |
| دفع مقدم | `CASH` / `WALLET` | `CUSTOMER_CREDIT` | |
| استخدام الرصيد المقدم لدين | `CUSTOMER_CREDIT` | `AR_*` | |
| مناقلة | `AR_PRIMARY` | `AR_SECONDARY` | |
| دين يدوي (أجور تنصيب مثلاً) | `AR_PRIMARY` (افتراضياً) | `REV_OTHER` | |
| دين افتتاحي (قديم قبل النظام) | `AR_PRIMARY` | `OPENING_EQUITY` | |
| الراجع من الشركة | `CASH` أو `COMPANY_BALANCE` | `REV_COMMISSION` | **هذا هو الربح من التفعيلات** |
| تقسيم الراجع: جزء للزون | `CASH:zone` | `CASH` أو `COMPANY_BALANCE` (المصدر) | تحويل داخلي |
| تقسيم الراجع: شريك أو راتب | `DISTRIBUTIONS` | `CASH` | |
| مصروف | `EXP_GENERAL` | `CASH` / `WALLET` | |
| بيع جهاز نقداً | `CASH` | `REV_DEVICE_SALES` | |
| بيع جهاز آجل | `AR_PRIMARY` | `REV_DEVICE_SALES` | |
| إلغاء أي عملية | عكس سطور القيد الأصلي تماماً | | `reverses_txn_id` |

**النتائج المباشرة من الدفتر:**
- إجمالي الديون الثانوية = رصيد `AR_SECONDARY`. في مثال أحمد وعلي = 70,000 تلقائياً.
- رصيد الشركة المتبقي = رصيد `COMPANY_BALANCE`، ويجب أن يطابق ما يظهر على موقع الشركة (مطابقة يدوية دورية).
- الربح = `REV_COMMISSION` + `REV_DEVICE_SALES` + `REV_OTHER` − `EXP_*`.

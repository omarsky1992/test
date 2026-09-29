# المرحلة 3 — مخطط الكيانات والعلاقات (ERD)

المخطط مقسوم إلى ثلاثة أجزاء لسهولة القراءة. تفاصيل الحقول الكاملة في [02-database.md](02-database.md).

## 3.1 المخطط الأساسي: المشتركون، التفعيل، المالية

```mermaid
erDiagram
    BRANCHES ||--o{ USERS : "يعمل فيه"
    BRANCHES ||--o{ SUBSCRIBERS : "يتبع"
    SUBSCRIBERS ||--|{ ACCOUNTS : "يملك (بيت أو أكثر)"
    ACCOUNTS }o--o| SERVICE_PLANS : "الفئة الحالية"

    SERVICE_PLANS ||--o{ ACTIVATIONS : "فئة التفعيل"
    PROMOTIONS |o--o{ ACTIVATIONS : "عرض مطبق"
    ACCOUNTS ||--o{ ACTIVATIONS : "يُفعَّل"
    ACTIVATIONS ||--|{ ACTIVATION_PERIODS : "فترات 7 / 23 / 30"

    ACCOUNTS ||--o{ DEBTS : "مدين بـ"
    ACTIVATIONS ||--o| DEBTS : "ينشئ"
    ACCOUNTS ||--o{ PAYMENTS : "يدفع (سند قبض)"
    PAYMENTS ||--o{ PAYMENT_ALLOCATIONS : "يوزَّع على"
    DEBTS ||--o{ PAYMENT_ALLOCATIONS : "يُسدَّد بـ"
    DEBTS ||--o{ DEBT_TRANSFERS : "ينقل (ثانوي ← أولي)"
    DEBT_TRANSFERS |o--o| ACTIVATION_PERIODS : "يضيف +23 يوم"
    PAYMENTS |o--o{ ACTIVATION_PERIODS : "يضيف +23 يوم"
    MONEY_ACCOUNTS ||--o{ PAYMENTS : "يستلم"

    FINANCIAL_TRANSACTIONS ||--|{ LEDGER_ENTRIES : "سطور متوازنة"
    FINANCIAL_TRANSACTIONS |o--o| FINANCIAL_TRANSACTIONS : "يعكس"
    LEDGER_ACCOUNTS ||--o{ LEDGER_ENTRIES : "يُرحَّل إلى"
    MONEY_ACCOUNTS ||--|| LEDGER_ACCOUNTS : "يقابله"
    PAYMENTS ||--|| FINANCIAL_TRANSACTIONS : "قيد"
    DEBTS ||--o{ LEDGER_ENTRIES : "حركات الدين"
    DEBT_TRANSFERS ||--|| FINANCIAL_TRANSACTIONS : "قيد"

    USERS ||--o{ ACTIVATIONS : "نفّذ"
    USERS ||--o{ PAYMENTS : "استلم"
    USERS ||--o{ DEBT_TRANSFERS : "نفّذ"
    ACCOUNTS ||--o{ FOLLOW_UPS : "متابعة واتصال"
    ACTIVATIONS |o--o{ FOLLOW_UPS : "بخصوص"

    SUBSCRIBERS {
        bigint id PK
        bigint branch_id FK
        varchar code UK "C-000123"
        varchar full_name
        varchar name_search "مطبّع للبحث"
        varchar phone_normalized "رقم المشترك في الشركة"
        varchar status
    }
    ACCOUNTS {
        bigint id PK
        bigint subscriber_id FK
        citext username UK "يوزر موقع الشركة"
        text secret_encrypted "باسورد مشفر"
        varchar serial_number UK
        varchar phone_normalized
        varchar fat_code "الفات"
        varchar pole_number "رقم العامود"
        timestamptz service_ends_at "نسخة للبحث"
        varchar status
    }
    SERVICE_PLANS {
        bigint id PK
        varchar code UK "basic plus turbo pro_max"
        varchar name_ar
        bigint price "35000 45000 65000 100000"
        smallint duration_days "30"
    }
    FOLLOW_UPS {
        bigint id PK
        bigint account_id FK
        bigint activation_id FK
        varchar channel "call whatsapp visit"
        varchar outcome
        timestamptz promised_at
        timestamptz next_follow_up_at
        bigint created_by FK
    }
    PROMOTIONS {
        bigint id PK
        varchar audience "new existing all"
        varchar funded_by "company agent"
        bigint plan_id FK
        varchar discount_type
        bigint discount_value
        timestamptz starts_at
        timestamptz ends_at
    }
    ACTIVATIONS {
        bigint id PK
        varchar number UK "A-2026-000001"
        bigint account_id FK
        bigint plan_id FK
        bigint promotion_id FK
        varchar kind "partial_7 full_30"
        bigint list_price
        bigint discount_amount
        bigint final_price
        varchar settlement "debt paid credit"
        varchar completion_status "not_required pending completed"
        varchar completed_via "payment transfer"
        timestamptz starts_at
        timestamptz ends_at
        bigint created_by FK
    }
    ACTIVATION_PERIODS {
        bigint id PK
        bigint activation_id FK
        bigint account_id FK "EXCLUDE overlap"
        varchar period_type "initial_7 initial_30 extension_23"
        smallint days
        timestamptz starts_at
        timestamptz ends_at
        varchar source_type "activation payment transfer"
        bigint source_id
        boolean is_void
    }
    DEBTS {
        bigint id PK
        varchar number UK "D-2026-000001"
        bigint account_id FK
        bigint subscriber_id FK
        bigint activation_id FK
        varchar source "activation manual opening device_sale"
        varchar bucket "secondary primary"
        bigint original_amount
        bigint paid_amount "cache"
        bigint balance "cache"
        varchar status "open partial paid voided"
        bigint created_by FK
    }
    PAYMENTS {
        bigint id PK
        varchar receipt_number UK "R-2026-000001"
        bigint account_id FK
        varchar payment_type "debt_payment advance"
        varchar method "cash electronic"
        bigint money_account_id FK
        varchar receiver_name
        bigint amount
        timestamptz received_at
        varchar status "posted voided"
        bigint txn_id FK
        uuid idempotency_key UK
    }
    PAYMENT_ALLOCATIONS {
        bigint id PK
        bigint payment_id FK
        bigint debt_id FK
        bigint amount
        bigint txn_id FK
    }
    DEBT_TRANSFERS {
        bigint id PK
        varchar number UK "T-2026-000001"
        bigint debt_id FK
        varchar from_bucket "secondary"
        varchar to_bucket "primary"
        bigint amount
        smallint days_added "23"
        bigint period_id FK
        text reason
        timestamptz performed_at
        bigint performed_by FK
    }
    MONEY_ACCOUNTS {
        bigint id PK
        varchar kind "cash electronic company"
        varchar name
        varchar holder_name
        bigint ledger_account_id FK
    }
    LEDGER_ACCOUNTS {
        bigint id PK
        varchar code UK "AR_SECONDARY AR_PRIMARY CASH..."
        varchar type "asset liability equity revenue expense"
    }
    FINANCIAL_TRANSACTIONS {
        bigint id PK
        varchar txn_type
        timestamptz occurred_at
        varchar source_type
        bigint source_id
        bigint reverses_txn_id FK "UNIQUE"
        uuid idempotency_key UK
    }
    LEDGER_ENTRIES {
        bigint id PK
        bigint txn_id FK
        bigint ledger_account_id FK
        bigint debit
        bigint credit
        bigint account_id FK
        bigint debt_id FK
    }
```

## 3.2 الصناديق والراجع والمصروفات والمبيعات والأجهزة

```mermaid
erDiagram
    MONEY_ACCOUNTS ||--o{ FUND_TRANSFERS : "من / إلى (شحن رصيد الشركة)"
    MONEY_ACCOUNTS ||--o{ COMPANY_SETTLEMENTS : "دخل الراجع إلى"
    COMPANY_SETTLEMENTS ||--o{ SETTLEMENT_DISTRIBUTIONS : "تقسيم"
    FUND_TRANSFERS ||--|| FINANCIAL_TRANSACTIONS : "قيد"
    COMPANY_SETTLEMENTS ||--|| FINANCIAL_TRANSACTIONS : "قيد"
    EXPENSE_CATEGORIES ||--o{ EXPENSES : "تصنيف"
    MONEY_ACCOUNTS ||--o{ EXPENSES : "دُفع من"
    EXPENSES |o--o{ DEVICE_SALES : "كلفة الشراء"
    DEVICE_TYPES ||--o{ DEVICE_SALES : "نوع المادة"
    DEVICE_TYPES ||--o{ DEVICES : "نوع الجهاز"
    SUBSCRIBERS |o--o{ DEVICE_SALES : "المشتري"
    DEVICE_SALES |o--o| DEBTS : "بيع آجل"
    DEVICE_SALES |o--o{ DEVICES : "الجهاز المباع"
    SUBSCRIBERS ||--o{ DEVICES : "يملك أو يستخدم"
    ACCOUNTS |o--o{ DEVICES : "مركّب على"
    DEVICES ||--o{ DEVICE_NOTES : "ملاحظات فنية"
    EXPENSES ||--|| FINANCIAL_TRANSACTIONS : "قيد"
    DEVICE_SALES ||--|| FINANCIAL_TRANSACTIONS : "قيد"

    FUND_TRANSFERS {
        bigint id PK
        varchar number UK "F-..."
        bigint from_money_account_id FK
        bigint to_money_account_id FK
        bigint amount
        timestamptz transferred_at
    }
    COMPANY_SETTLEMENTS {
        bigint id PK
        varchar number UK "K-..."
        date period_from
        date period_to
        bigint amount "الراجع"
        bigint received_into_money_account_id FK
        int activations_count
    }
    SETTLEMENT_DISTRIBUTIONS {
        bigint id PK
        bigint settlement_id FK
        varchar kind "zone_fund partner salary other"
        varchar beneficiary
        bigint amount
    }
    EXPENSES {
        bigint id PK
        varchar number UK "E-..."
        bigint category_id FK
        bigint amount
        bigint money_account_id FK
        timestamptz spent_at
        varchar status
    }
    DEVICE_SALES {
        bigint id PK
        varchar number UK "S-..."
        bigint subscriber_id FK
        bigint item_type_id FK
        bigint total_amount
        bigint cost_amount
        bigint purchase_expense_id FK
        varchar settlement "paid debt"
        varchar status
    }
    DEVICES {
        bigint id PK
        bigint subscriber_id FK
        bigint account_id FK
        bigint device_type_id FK
        varchar serial_number
        varchar mac_address
        varchar ownership "subscriber company_loan sold"
        varchar status
        jsonb attributes
    }
    DEVICE_NOTES {
        bigint id PK
        bigint device_id FK
        text note
        bigint created_by FK
    }
```

## 3.3 الهوية، التدقيق، التكامل

```mermaid
erDiagram
    USERS }o--o{ ROLES : "model_has_roles"
    ROLES }o--o{ PERMISSIONS : "role_has_permissions"
    USERS }o--o{ PERMISSIONS : "صلاحية مباشرة"
    USERS ||--o{ AUDIT_LOGS : "نفّذ"
    SYNC_RUNS |o--o{ AUDIT_LOGS : "أثر المزامنة"

    INTEGRATION_SOURCES ||--o{ SYNC_RUNS : "دفعات"
    SYNC_RUNS ||--o{ SYNC_ITEMS : "سجلات مرحلية"
    SYNC_ITEMS ||--o{ SYNC_CONFLICTS : "تعارضات"
    INTEGRATION_SOURCES ||--o{ EXTERNAL_LINKS : "معرفات خارجية"
    INTEGRATION_SOURCES ||--o{ SYNC_FIELD_POLICIES : "ملكية الحقول"
    EXTERNAL_LINKS }o--|| ACCOUNTS : "entity (polymorphic)"

    USERS {
        bigint id PK
        bigint branch_id FK
        citext username UK
        varchar password_hash
        boolean is_active
    }
    AUDIT_LOGS {
        bigint id PK
        timestamptz occurred_at
        bigint user_id FK
        varchar action
        varchar entity_type
        bigint entity_id
        jsonb old_values
        jsonb new_values
        text reason
        varchar source "web api sync system"
    }
    SYNC_RUNS {
        bigint id PK
        bigint source_id FK
        varchar status
        varchar file_hash
        jsonb stats
        bigint retry_of_run_id FK
    }
    SYNC_ITEMS {
        bigint id PK
        bigint run_id FK
        varchar external_id
        jsonb raw_payload
        varchar payload_hash
        varchar action "create update unchanged missing conflict error"
    }
    EXTERNAL_LINKS {
        bigint id PK
        bigint source_id FK
        varchar entity_type
        bigint entity_id
        varchar external_id
        timestamptz last_synced_at
        timestamptz missing_since
    }
    SYNC_CONFLICTS {
        bigint id PK
        bigint sync_item_id FK
        varchar field
        text local_value
        text external_value
        varchar resolution
    }
```

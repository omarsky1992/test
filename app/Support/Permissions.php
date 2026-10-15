<?php

namespace App\Support;

/**
 * The permission catalogue (docs/architecture/05-permissions.md). Employee defaults are marked;
 * the admin role passes every check through Gate::before.
 */
class Permissions
{
    /** @var array<string, array{0: string, 1: bool}> name => [Arabic label, granted to employees by default] */
    public const ALL = [
        'subscribers.view' => ['عرض المشتركين', true],
        'subscribers.create' => ['إضافة مشترك', true],
        'subscribers.update' => ['تعديل مشترك', true],
        'subscribers.archive' => ['أرشفة أو حظر مشترك', false],
        'accounts.view' => ['عرض الحسابات', true],
        'accounts.create' => ['إضافة حساب', true],
        'accounts.update' => ['تعديل حساب', true],
        'accounts.view_secret' => ['إظهار باسورد الاشتراك', true],
        'accounts.close' => ['إغلاق حساب', false],
        'activations.create' => ['تفعيل', true],
        'activations.edit_start' => ['تعديل وقت بداية التفعيل', true],
        'activations.override_price' => ['سعر يدوي خارج العروض', false],
        'activations.void' => ['إلغاء تفعيل خاطئ', false],
        'debts.view' => ['عرض الديون', true],
        'debts.create_manual' => ['دين يدوي', false],
        'debts.create_opening' => ['دين افتتاحي', false],
        'debts.void' => ['حذف (إلغاء) دين', false],
        'transfers.create' => ['مناقلة', true],
        'payments.create' => ['تسجيل قبض', true],
        'payments.backdate' => ['قبض بتاريخ سابق', false],
        'payments.void' => ['إلغاء سند قبض', false],
        'receipts.print' => ['طباعة السند', true],
        'expenses.create' => ['تسجيل مصروف', true],
        'sales.create' => ['تسجيل بيع أجهزة ومواد', true],
        'sales.void' => ['إلغاء عملية بيع', false],
        'expenses.void' => ['إلغاء مصروف', false],
        'funds.transfer' => ['تحويل بين الصناديق وشحن رصيد الشركة', false],
        'settlements.manage' => ['الراجع وتقسيمه', false],
        'cash.opening_balance' => ['الرصيد الابتدائي', false],
        'cash.allow_negative' => ['صرف يجعل القاصة سالبة', false],
        'follow_ups.create' => ['تسجيل نتيجة اتصال', true],
        'devices.manage' => ['الأجهزة', true],
        'reports.view' => ['التقارير', true],
        'plans.manage' => ['الفئات والأسعار', false],
        'promotions.manage' => ['العروض', false],
        'money_accounts.manage' => ['القاصات والمحافظ', false],
        'users.manage' => ['المستخدمون والصلاحيات', false],
        'audit.view' => ['سجل العمليات', false],
        'settings.manage' => ['الإعدادات', false],
        'sync.run' => ['مزامنة المشتركين من موقع الشركة', false],
        'employees.view' => ['عرض الموظفين وكشوف حساباتهم', false],
        'custody.settle' => ['تسديد عهدة موظف للصندوق', false],
        'advances.manage' => ['تسجيل السلف وتسديدها', false],
        'activations.mark_done' => ['تأكيد «تم التفعيل» لمن سدّد دينه الثانوي', true],
        'whatsapp.remind' => ['إرسال تذكير واتساب للمشتركين من القوالب', true],
        'notes.view' => ['قراءة «وضع المشترك» (الملاحظات)', true],
        'notes.create' => ['إضافة ملاحظة على مشترك', true],
        'notes.close' => ['إنهاء ملاحظة', true],
        'notes.delete' => ['حذف ملاحظة', false],
        'blacklist.view' => ['عرض القائمة السوداء', true],
        'blacklist.manage' => ['الإضافة إلى القائمة السوداء والإزالة منها', false],
        'whatsapp.broadcast' => ['الرسائل الجماعية عبر واتساب', false],
        'whatsapp.manage' => ['لوحة تحكم واتساب: الأرقام المصرح لها وسجل العمليات', false],
        // Screens that were open to everyone before the per-employee permissions: employees keep them by default.
        'dashboard.view' => ['فتح لوحة تحكم المدير (الأرصدة والإحصائيات)', false],
        'subscribers.export' => ['تصدير المشتركين إلى Excel', false],
        'import.run' => ['استيراد المشتركين من Excel', true],
        'activations.view' => ['عرض سجل التفعيلات', true],
        'transfers.view' => ['عرض سجل المناقلات (الديون)', true],
        'payments.view' => ['عرض سندات القبض', true],
        'expenses.view' => ['عرض المشتريات والمصروفات', true],
        'sales.view' => ['عرض المبيعات', true],
        'follow_ups.view' => ['عرض قائمة متابعة الـ7 أيام', true],
        'money_accounts.view' => ['عرض الصناديق وأرصدتها', true],
        'custody_transfers.view' => ['عرض المناقلات بين حسابات الموظفين', false],
        'custody_transfers.create' => ['مناقلة بين حسابات الموظفين', false],
        'custody_transfers.void' => ['إلغاء مناقلة بين الموظفين', false],
        'cards.view' => ['عرض حسابات البطاقات وأرصدتها وحركاتها', false],
        'cards.transfer' => ['المناقلة بين البطاقات', false],
        'cards.reset' => ['تصفير رصيد بطاقة', false],
        'cards.void' => ['إلغاء مناقلة أو تصفير بطاقة', false],
        'periods.view' => ['عرض الفترات المحاسبية وتقاريرها', false],
        'periods.close' => ['إغلاق الفترات المحاسبية وفتح فترة جديدة', false],
        'stock.view' => ['عرض مخزون الكارتات', false],
        'stock.add' => ['إضافة كارتات للمخزون', false],
        'stock.activate' => ['تفعيل كارت', false],
        'stock.void' => ['إلغاء كارت (تالف أو خطأ)', false],
        'stock.view_codes' => ['إظهار أرقام الكارتات كاملة', false],
        'locations.view' => ['مشاهدة موقع بيت المشترك', true],
        'locations.create' => ['إضافة موقع لبيت المشترك', true],
        'locations.update' => ['تعديل أو استبدال موقع المشترك', true],
        'locations.delete' => ['حذف موقع المشترك', false],
        'locations.directions' => ['فتح الاتجاهات إلى بيت المشترك', true],
        'photos.view' => ['مشاهدة صورة بيت المشترك', true],
        'photos.add' => ['إضافة صورة لبيت المشترك', true],
        'photos.delete' => ['حذف أو استبدال صورة البيت', false],
    ];

    /** The sections of «إدارة صلاحيات الموظفين»: label => permission names in that section. */
    public const GROUPS = [
        'لوحة التحكم والتقارير' => ['dashboard.view', 'reports.view', 'employees.view', 'audit.view'],
        'المشتركون' => ['subscribers.view', 'subscribers.create', 'subscribers.update', 'subscribers.archive', 'accounts.view', 'accounts.create', 'accounts.update', 'accounts.view_secret', 'accounts.close', 'devices.manage'],
        'وضع المشترك والقائمة السوداء' => ['notes.view', 'notes.create', 'notes.close', 'notes.delete', 'blacklist.view', 'blacklist.manage'],
        'مواقع المشتركين وصور بيوتهم' => ['locations.view', 'locations.create', 'locations.update', 'locations.delete', 'locations.directions', 'photos.view', 'photos.add', 'photos.delete'],
        'التفعيل والتجديد' => ['activations.view', 'activations.create', 'activations.edit_start', 'activations.override_price', 'activations.void', 'activations.mark_done', 'follow_ups.view', 'follow_ups.create'],
        'الديون والتحصيل' => ['debts.view', 'debts.create_manual', 'debts.create_opening', 'debts.void', 'transfers.view', 'transfers.create', 'payments.view', 'payments.create', 'payments.backdate', 'payments.void', 'receipts.print'],
        'المشتريات والمبيعات' => ['expenses.view', 'expenses.create', 'expenses.void', 'sales.view', 'sales.create', 'sales.void', 'periods.view', 'periods.close'],
        'الصناديق وحسابات الموظفين' => ['money_accounts.view', 'money_accounts.manage', 'funds.transfer', 'cash.opening_balance', 'cash.allow_negative', 'settlements.manage', 'custody.settle', 'advances.manage', 'custody_transfers.view', 'custody_transfers.create', 'custody_transfers.void'],
        'حسابات البطاقات' => ['cards.view', 'cards.transfer', 'cards.reset', 'cards.void'],
        'مخزون الكارتات' => ['stock.view', 'stock.add', 'stock.activate', 'stock.void', 'stock.view_codes'],
        'الاستيراد والتصدير' => ['import.run', 'subscribers.export', 'sync.run'],
        'واتساب' => ['whatsapp.remind', 'whatsapp.broadcast', 'whatsapp.manage'],
        'الإعدادات والمستخدمون' => ['settings.manage', 'plans.manage', 'promotions.manage', 'users.manage'],
    ];

    public static function employeeDefaults(): array
    {
        return array_keys(array_filter(self::ALL, fn (array $p) => $p[1]));
    }
}

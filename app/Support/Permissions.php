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
        'whatsapp.manage' => ['لوحة تحكم واتساب: الأرقام المصرح لها وسجل العمليات', false],
    ];

    public static function employeeDefaults(): array
    {
        return array_keys(array_filter(self::ALL, fn (array $p) => $p[1]));
    }
}

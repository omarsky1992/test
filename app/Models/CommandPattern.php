<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class CommandPattern extends Model
{
    /** What a phrasing does, and the blanks it must contain. */
    public const ACTIONS = [
        'activate' => ['تفعيل (دين ثانوي حتى 7 أيام)', ['{الاسم}']],
        'payment' => ['قبض', ['{الاسم}', '{المبلغ}']],
        'add_debt_primary' => ['تسجيل دين أولي', ['{الاسم}', '{المبلغ}']],
        'add_debt_secondary' => ['تسجيل دين ثانوي', ['{الاسم}', '{المبلغ}']],
        'transfer' => ['مناقلة (ثانوي إلى أولي)', ['{الاسم}']],
        'void_debt' => ['مسح دين', ['{الاسم}']],
        'purchase' => ['مشتريات / مصروف', ['{المادة}']],
        'sale' => ['مبيعات', ['{المادة}']],
        'subscriber_debt' => ['استعلام: دين مشترك', ['{الاسم}']],
        'query:secondary_debts' => ['استعلام: الديون الثانوية', []],
        'query:primary_debts' => ['استعلام: الديون الأولية', []],
        'query:late' => ['استعلام: المتأخرين', []],
        'query:must_activate' => ['استعلام: يجب التفعيل', []],
        'query:activated_today' => ['استعلام: المفعلين اليوم', []],
        'query:sales_today' => ['استعلام: مبيعات اليوم', []],
        'query:purchases_today' => ['استعلام: مشتريات اليوم', []],
        'query:custody' => ['استعلام: عهد الموظفين', []],
        'query:advances' => ['استعلام: سلف الموظفين', []],
    ];

    public const BLANKS = [
        '{الاسم}' => 'اسم المشترك أو رقمه أو اليوزر',
        '{الأيام}' => 'عدد الأيام (7، سبعة، شهر…)',
        '{المبلغ}' => 'المبلغ (35 الف، خمسة وثلاثين ألف…)',
        '{المادة}' => 'المادة وسعرها (كيبل 30 متر سعر المتر 5 آلاف)',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'amount_in_thousands' => 'boolean', 'default_days' => 'integer'];
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action][0] ?? $this->action;
    }
}

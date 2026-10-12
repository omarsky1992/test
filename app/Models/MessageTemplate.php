<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class MessageTemplate extends Model
{
    /** Placeholders an admin may use, with what each becomes. */
    public const PLACEHOLDERS = [
        '{الاسم}' => 'اسم المشترك',
        '{الفئة}' => 'الفئة',
        '{الأيام}' => 'الأيام المتبقية',
        '{المبلغ}' => 'المبلغ المتبقي عليه',
        '{تاريخ_الانتهاء}' => 'تاريخ انتهاء الاشتراك',
        '{اليوزر}' => 'اسم الجهاز (اليوزر)',
        '{المبلغ_المستلم}' => 'المبلغ المستلم (رسالة الدفعة)',
        '{طريقة_الدفع}' => 'طريقة الدفع (رسالة الدفعة)',
        '{تاريخ_الدفع}' => 'تاريخ الدفعة (رسالة الدفعة)',
        '{رقم_الوصل}' => 'رقم سند القبض (رسالة الدفعة)',
        '{رقم_العملية}' => 'رقم العملية إن وُجد (رسالة الدفعة)',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  array<string, string>  $values  placeholder => value
     */
    public function render(array $values): string
    {
        return strtr($this->body, $values);
    }
}

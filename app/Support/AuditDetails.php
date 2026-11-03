<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * «سجل العمليات» in words: to whom (the subscriber and the account), from where (panel, WhatsApp,
 * sync…, the box the money went to or came from, the device) and how (amounts, methods, numbers).
 * It only reads: audit entries are append-only, so what an older entry did not write (the box of a
 * receipt, the account) is read from the record it points at.
 */
class AuditDetails
{
    public const SOURCES = [
        'web' => 'لوحة التحكم',
        'api' => 'الإضافة / API',
        'sync' => 'مزامنة موقع الشركة',
        'system' => 'النظام',
        'whatsapp' => 'واتساب',
    ];

    /** Field names written by the services, in Arabic. */
    public const KEYS = [
        'number' => 'الرقم', 'receipt' => 'رقم السند', 'amount' => 'المبلغ', 'total' => 'الإجمالي', 'type' => 'النوع',
        'methods' => 'طريقة الدفع', 'method' => 'طريقة الدفع', 'allocations' => 'سدّد الديون', 'credit' => 'رصيد مقدم',
        'money_account' => 'الصندوق', 'from' => 'من', 'to' => 'إلى', 'into' => 'إلى الصندوق', 'box' => 'الصندوق',
        'debt' => 'الدين', 'bucket' => 'نوع الدين', 'source' => 'المصدر', 'renewal' => 'التجديد', 'kind' => 'النوع',
        'plan' => 'الفئة', 'starts_at' => 'من', 'ends_at' => 'إلى', 'final_price' => 'السعر', 'days_added' => 'أيام مضافة',
        'category' => 'الفئة', 'description' => 'الوصف', 'settlement' => 'التسوية', 'custody' => 'العهدة بعدها',
        'employee' => 'الموظف', 'confirmed_by' => 'أكّده', 'reason' => 'السبب', 'name' => 'الاسم', 'full_name' => 'الاسم',
        'phone' => 'الهاتف', 'alt_phone' => 'الهاتف الثاني', 'username' => 'اسم الجهاز', 'status' => 'الحالة', 'line' => 'الخط',
        'muted' => 'إيقاف الرسائل', 'notify_alt_phone' => 'الرسائل إلى الرقم الثاني', 'price' => 'السعر', 'decision' => 'القرار',
        'days' => 'الأيام', 'old' => 'السابق', 'new' => 'الجديد', 'code' => 'الكود', 'denomination' => 'الفئة', 'count' => 'العدد',
        'external_ends_at' => 'تاريخ الانتهاء', 'notes' => 'ملاحظات', 'title' => 'العنوان', 'body' => 'النص', 'text' => 'النص',
        'command' => 'الأمر', 'payment_source' => 'نوع الدفع', 'balance' => 'الرصيد', 'opening_balance' => 'الرصيد الابتدائي',
    ];

    /** Fields that hold whole dinars. */
    private const MONEY = ['amount', 'total', 'credit', 'final_price', 'custody', 'price', 'balance', 'opening_balance', 'company_amount'];

    private const VALUES = [
        'type' => ['debt_payment' => 'تسديد دين', 'advance' => 'دفع مقدم'],
        'bucket' => ['primary' => 'دين أولي', 'secondary' => 'دين ثانوي'],
        'source' => ['activation' => 'تفعيل', 'manual' => 'يدوي', 'opening' => 'افتتاحي', 'device_sale' => 'بيع جهاز', 'renewal' => 'تجديد تلقائي'],
        'kind' => ['partial_7' => '7 أيام', 'full_30' => '30 يوم'],
        'settlement' => ['cash' => 'نقداً', 'debt' => 'دين'],
        'line' => ['main' => 'الرقم الرئيسي', 'subscriber' => 'خط المشتركين'],
    ];

    /** @var array<string, ?Model> */
    private static array $records = [];

    /** The subscriber and the account the operation was on, e.g. «علي حسن — ali_123». */
    public static function target(AuditLog $log): ?string
    {
        $name = $log->subscriber?->full_name;
        $account = self::account($log);

        return collect([$name, $account?->username])->filter()->unique()->join(' — ') ?: null;
    }

    /** Where it came from and where the money went: «لوحة التحكم · صندوق: عهدة أحمد · هاتف». */
    public static function origin(AuditLog $log): string
    {
        $boxes = self::boxes($log);

        return collect([
            self::SOURCES[$log->source] ?? $log->source,
            $boxes ? 'الصندوق: '.$boxes : null,
            self::device($log->user_agent),
        ])->filter()->join(' · ');
    }

    /**
     * What was done, one readable line per field.
     *
     * @return array<string, string> label => value
     */
    public static function details(?array $values): array
    {
        $out = [];
        foreach ($values ?? [] as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $label = self::KEYS[$key] ?? (string) $key;
            $out[$label] = self::value((string) $key, $value);
        }

        return $out;
    }

    /** Everything on one line for the list: the changes (old → new) or the new values. */
    public static function summary(AuditLog $log): string
    {
        $new = self::details($log->new_values);
        $old = self::details($log->old_values);

        return collect($new)->map(fn ($v, $k) => isset($old[$k]) && $old[$k] !== $v ? "{$k}: {$old[$k]} ← {$v}" : "{$k}: {$v}")->join(' · ');
    }

    public static function value(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }
        if (is_array($value)) {
            // [['نقدي' => 25000], ...] or ['D-1' => 5000] → «نقدي 25,000 + ...»
            return collect($value)->map(function ($item, $k) use ($key) {
                if (is_array($item)) {
                    return collect($item)->map(fn ($v, $kk) => trim((is_int($kk) ? '' : $kk.' ').self::value(is_int($kk) ? $key : 'amount', $v)))->join(' + ');
                }

                return trim((is_int($k) ? '' : $k.' ').self::value(is_int($k) ? $key : 'amount', $item));
            })->filter()->join(' + ');
        }
        if (in_array($key, self::MONEY, true) && is_numeric($value)) {
            return Money::format((int) $value);
        }
        if (isset(self::VALUES[$key][$value])) {
            return self::VALUES[$key][$value];
        }
        if (is_string($value) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d/', $value)) {
            return rescue(fn () => CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y/m/d H:i'), $value, false);
        }

        return (string) $value;
    }

    /** «هاتف» or «حاسبة» from the browser's description. */
    public static function device(?string $agent): ?string
    {
        if (! $agent) {
            return null;
        }

        return preg_match('/Mobile|Android|iPhone|iPad/i', $agent) ? 'هاتف' : 'حاسبة';
    }

    /** The box(es) the money of this entry went to or came from. */
    public static function boxes(AuditLog $log): ?string
    {
        $values = $log->new_values ?? [];
        if (! empty($values['money_account']) && is_string($values['money_account'])) {
            return $values['money_account'];
        }
        $record = self::record($log);
        if ($record instanceof Payment) {
            $record->loadMissing('lines.moneyAccount');
            $names = $record->lines->map(fn ($l) => $l->moneyAccount?->name)->filter()->unique();

            return $names->isNotEmpty() ? $names->join(' + ') : $record->moneyAccount?->name;
        }
        if ($record && method_exists($record, 'moneyAccount')) {
            return $record->moneyAccount?->name;
        }

        return null;
    }

    private static function account(AuditLog $log): ?Account
    {
        $record = self::record($log);
        if ($record instanceof Account) {
            return $record;
        }

        return $record && method_exists($record, 'account') ? $record->account()->first() : null;
    }

    private static function record(AuditLog $log): ?Model
    {
        if (! $log->entity_type || ! $log->entity_id) {
            return null;
        }
        $key = $log->getConnection()->getDatabaseName().':'.$log->entity_type.':'.$log->entity_id;
        if (! array_key_exists($key, self::$records)) {
            $class = 'App\\Models\\'.$log->entity_type;
            self::$records[$key] = class_exists($class) && is_subclass_of($class, Model::class)
                ? rescue(fn () => $class::query()->find($log->entity_id), null, false)
                : null;
        }

        return self::$records[$key];
    }

    /** Drops what was read (tests, tenant switches). */
    public static function forget(): void
    {
        self::$records = [];
    }
}

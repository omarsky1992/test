<?php

namespace App\WhatsApp;

use App\Support\Arabic;

/**
 * Fixed patterns for the short, common commands, so they work instantly and without the AI
 * service. Anything that does not match exactly returns null and goes to the AI interpreter.
 */
class RuleInterpreter implements Interpreter
{
    private const WORD_NUMBERS = [
        'واحد' => 1, 'يوم' => 1, 'يومين' => 2, 'اثنين' => 2, 'ثنين' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'تلث' => 3,
        'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7,
        'ثمان' => 8, 'ثمانيه' => 8, 'ثمن' => 8, 'تسع' => 9, 'تسعه' => 9, 'عشر' => 10, 'عشره' => 10,
        'عشرين' => 20, 'ثلاثين' => 30, 'تلاثين' => 30,
    ];

    public function interpret(string $text, ?array $previous = null): ?Command
    {
        $command = $this->match($text);
        if ($command?->subscriber !== null) {
            $command->subscriber = trim(preg_replace('/^بخصوص\s+/u', '', $command->subscriber));
        }

        return $command;
    }

    private function match(string $text): ?Command
    {
        // «على» (on) becomes «علي» after normalizing, the same as the name Ali: mark it first.
        $t = self::clean(preg_replace('/(^|\s)على(?=\s)/u', '$1 بخصوص', $text));
        if ($t === '') {
            return null;
        }

        // «امسح دين محمد»
        if (preg_match('/^(?:امسح|احذف|الغي|شيل)\s+(?:دين|ديون)\s+(.+)$/u', $t, $m)) {
            return new Command('void_debt', subscriber: $m[1]);
        }
        // «شكد دين محمد» / «كم دين محمد»
        if (preg_match('/^(?:شكد|كم|شقد)\s+(?:دين|ديون|عليه\s+دين)\s+(.+)$/u', $t, $m)) {
            return new Command('query', subscriber: $m[1], query: 'subscriber_debt');
        }
        // «محمد رمضان فعلته سبع أيام» / «فعلت محمد رمضان شهر»
        if (preg_match('/^(.+?)\s+(?:فعلته|فعلتها|فعلتلو|فعلتله|تفعيل|تفعيله)\s+(.+)$/u', $t, $m)
            && ($days = self::days($m[2])) !== null) {
            return new Command('activate', subscriber: $m[1], days: $days);
        }
        if (preg_match('/^(?:فعلت|فعل|تفعيل)\s+(.+)$/u', $t, $m)) {
            // The days are the last one or two words: «فعلت محمد رمضان شهر», «فعلت علي سبع ايام».
            $words = explode(' ', $m[1]);
            foreach ([2, 1] as $n) {
                if (count($words) > $n && ($days = self::days(implode(' ', array_slice($words, -$n)))) !== null) {
                    return new Command('activate', subscriber: implode(' ', array_slice($words, 0, -$n)), days: $days);
                }
            }
        }
        // «سجل دين على محمد 25 الف» / «سجل دين اولي على محمد 25 الف»
        if (preg_match('/^(?:سجل|سجلي|اضف|ضيف|حط|قيد)\s+(?:دين|ديون)(?:\s+(اولي|ثانوي))?\s+(?:بخصوص\s+|ل)?(.+)$/u', $t, $m)
            && ($debt = self::nameAndAmount($m[2])) !== null) {
            return new Command('add_debt', subscriber: $debt[0], amount: $debt[1], bucket: $m[1] === 'ثانوي' ? 'secondary' : 'primary');
        }
        // «محمد عليه 25 الف دين» / «محمد عليه دين اولي 25 الف»
        if (preg_match('/^(.+?)\s+عليه\s+(?:دين\s+)?(?:(اولي|ثانوي)\s+)?(.+?)(?:\s+دين(?:\s+(اولي|ثانوي))?)?$/u', $t, $m)
            && ($amount = self::amount($m[3])) !== null) {
            $bucket = ($m[2] ?? '') === 'ثانوي' || ($m[4] ?? '') === 'ثانوي' ? 'secondary' : 'primary';

            return new Command('add_debt', subscriber: $m[1], amount: $amount, bucket: $bucket);
        }
        // «محمد رمضان دفع 35 الف»
        if (preg_match('/^(.+?)\s+(?:دفع|سدد|واصل|وصل|انطى|انطاني|جاب)\s+(.+)$/u', $t, $m)
            && ($amount = self::amount($m[2])) !== null) {
            return new Command('payment', subscriber: $m[1], amount: $amount);
        }
        if ($query = self::query($t)) {
            return new Command('query', query: $query);
        }

        return null;
    }

    /**
     * «محمد رمضان 25 الف» → [name, amount]: the amount is the last one or two words.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function nameAndAmount(string $text): ?array
    {
        $words = explode(' ', trim(preg_replace('/^بخصوص\s+/u', '', $text)));
        foreach ([2, 1] as $n) {
            if (count($words) > $n && ($amount = self::amount(implode(' ', array_slice($words, -$n)))) !== null) {
                return [implode(' ', array_slice($words, 0, -$n)), $amount];
            }
        }

        return null;
    }

    /** The whole message is one of the fixed questions. */
    private static function query(string $t): ?string
    {
        if (mb_strlen($t) > 40) {
            return null;
        }

        return match (true) {
            str_contains($t, 'يجب التفعيل') || str_contains($t, 'لازم يتفعل') => 'must_activate',
            str_contains($t, 'ثانوي') => 'secondary_debts',
            str_contains($t, 'اولي') => 'primary_debts',
            str_contains($t, 'متاخر') => 'late',
            str_contains($t, 'مفعل') || str_contains($t, 'تفعيلات') => 'activated_today',
            str_contains($t, 'مبيعات') => 'sales_today',
            str_contains($t, 'مشتريات') || str_contains($t, 'مصاريف') => 'purchases_today',
            str_contains($t, 'عهد') => 'custody',
            str_contains($t, 'سلف') => 'advances',
            default => null,
        };
    }

    /** «سبع أيام», «7 يوم», «يومين», «شهر», «اسبوع» → days; anything else → null. */
    public static function days(string $text): ?int
    {
        $t = self::clean($text);
        if (preg_match('/^(?:ل|لمده\s+)?(شهر|اسبوع|يومين)$/u', $t, $m)) {
            return ['شهر' => 30, 'اسبوع' => 7, 'يومين' => 2][$m[1]];
        }
        if (preg_match('/^(?:ل|لمده\s+)?(\d+|\S+)\s+(?:يوم|ايام)$/u', $t, $m)) {
            $n = ctype_digit($m[1]) ? (int) $m[1] : (self::WORD_NUMBERS[$m[1]] ?? null);

            return $n !== null && $n > 0 && $n <= 365 ? $n : null;
        }

        return null;
    }

    /** «35 الف», «35000», «35,000 دينار», «ربع مليون» → whole dinars; anything else → null. */
    public static function amount(string $text): ?int
    {
        $t = str_replace([',', '٬', '،'], '', self::clean($text));
        $t = trim(preg_replace('/\s*(?:دينار|د\.ع|نقدا|نقد|كاش)$/u', '', $t));
        $named = ['ربع مليون' => 250000, 'نص مليون' => 500000, 'مليون' => 1000000, 'الف' => 1000];
        if (isset($named[$t])) {
            return $named[$t];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(الف|الاف|ورقه|اوراق|مليون)?$/u', $t, $m)) {
            $unit = match ($m[2] ?? '') {
                'الف', 'الاف', 'ورقه', 'اوراق' => 1000,
                'مليون' => 1000000,
                default => 1,
            };
            $value = (int) round((float) $m[1] * $unit);

            return $value > 0 ? $value : null;
        }

        return null;
    }

    /** Normalized Arabic with Western digits and no punctuation at the ends. */
    public static function clean(string $text): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $text = Arabic::normalize($text);

        return preg_replace('/^[\s.!?؟،,]+|[\s.!?؟،,]+$/u', '', $text);
    }
}

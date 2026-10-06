<?php

namespace App\WhatsApp;

use App\Services\Settings;
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

    /** «فعلته» and the ways it comes out of speech-to-text. */
    private const ACTIVATE_VERBS = 'فعلته|فعلتها|فعلتلو|فعلتله|فعلتلها|فعلتو|فعلتوا|فعلت له|فعلت الو|فعلت لها|فعلت|تفعيل|تفعيله|جددته|جددتله|جددتلو|جددت له|جددت';

    private const UNITS = [
        'صفر' => 0, 'واحد' => 1, 'وحده' => 1, 'اثنين' => 2, 'ثنين' => 2, 'اثنان' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'تلاث' => 3, 'تلاثه' => 3,
        'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7,
        'ثمان' => 8, 'ثمانيه' => 8, 'ثمنيه' => 8, 'ثماني' => 8, 'تسع' => 9, 'تسعه' => 9,
        'عشر' => 10, 'عشره' => 10, 'احدعش' => 11, 'اثنعش' => 12, 'ثنعش' => 12, 'ثلطعش' => 13, 'ثلاثطعش' => 13, 'اربعطعش' => 14,
        'خمسطعش' => 15, 'ستطعش' => 16, 'سبعطعش' => 17, 'ثمنطعش' => 18, 'تسعطعش' => 19,
        'عشرين' => 20, 'ثلاثين' => 30, 'تلاثين' => 30, 'اربعين' => 40, 'خمسين' => 50, 'ستين' => 60, 'سبعين' => 70, 'ثمانين' => 80, 'تسعين' => 90,
        'ميه' => 100, 'مئه' => 100, 'ميت' => 100, 'ميتين' => 200, 'مئتين' => 200, 'ثلاثميه' => 300, 'تلثميه' => 300, 'اربعميه' => 400,
        'خمسميه' => 500, 'ستميه' => 600, 'سبعميه' => 700, 'ثمنميه' => 800, 'ثمانميه' => 800, 'تسعميه' => 900, 'نص' => 0.5, 'ربع' => 0.25,
    ];

    /**
     * Numbers said in words, the way speech-to-text writes them: «خمسة وثلاثين الف» → 35000,
     * «مية وخمسين الف» → 150000, «ربع مليون» → 250000. Null when any word is not a number.
     */
    public static function wordsToNumber(string $text): ?int
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/(^|\s)و(?=\S)/u', '$1', $text))) ?: [];
        if ($words === [] || $words === ['']) {
            return null;
        }
        [$total, $group, $seen] = [0, 0.0, false];
        foreach ($words as $word) {
            if (isset(self::UNITS[$word])) {
                $group += self::UNITS[$word];
                $seen = true;
            } elseif (in_array($word, ['الف', 'الاف', 'ورقه', 'اوراق'], true)) {
                $total += ($seen ? $group : 1) * 1000;
                [$group, $seen] = [0.0, false];
            } elseif ($word === 'مليون' || $word === 'ملايين') {
                $total += ($seen ? $group : 1) * 1000000;
                [$group, $seen] = [0.0, false];
            } elseif (! in_array($word, ['دينار', 'فقط'], true)) {
                return null;
            }
        }
        $value = (int) round($total + $group);

        return $value > 0 ? $value : null;
    }

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
        // «تفعيل محمد دين اولي» / «فعلت محمد شهر بدين اولي 35 الف»: activated on a primary debt.
        if (preg_match('/^(.+?)\s+(?:بخصوص\s+)?ب?(?:دين|ديون)\s+(?:اولي|اوليه)(?:\s+(.+))?$/u', $t, $m)) {
            $amount = isset($m[2]) ? self::amount($m[2]) : null;
            $head = $this->match($m[1]);
            if ($head?->intent === 'activate') {
                [$name, $days] = [$head->subscriber, $head->days];
            } elseif (preg_match('/^(?:'.self::ACTIVATE_VERBS.'|فعل|تجديد)\s+(.+)$/u', $m[1], $a)) {
                [$name, $days] = [$a[1], app(Settings::class)->fullDays()];
            }
            if (isset($name) && (! isset($m[2]) || $amount !== null)) {
                return new Command('add_debt', subscriber: $name, days: $days, amount: $amount, bucket: 'primary');
            }
        }
        // «محمد رمضان فعلته سبع أيام» / «فعلت محمد رمضان شهر»
        if (preg_match('/^(.+?)\s+(?:'.self::ACTIVATE_VERBS.')\s+(.+)$/u', $t, $m)
            && ($days = self::days($m[2])) !== null) {
            return new Command('activate', subscriber: $m[1], days: $days);
        }
        if (preg_match('/^(?:فعلت|فعل|تفعيل|جددت|تجديد)\s+(.+)$/u', $t, $m)) {
            // The days are the last one or two words: «فعلت محمد رمضان شهر», «فعلت علي سبع ايام».
            $words = explode(' ', $m[1]);
            foreach ([3, 2, 1] as $n) {
                if (count($words) > $n && ($days = self::days(implode(' ', array_slice($words, -$n)))) !== null) {
                    return new Command('activate', subscriber: implode(' ', array_slice($words, 0, -$n)), days: $days);
                }
            }
        }
        // «ناقل دين محمد» / «مناقله محمد» / «انقل دين محمد للاولي»
        if (preg_match('/^(?:ناقل|ناقله|مناقله|نقل|انقل)\s+(?:دين\s+|ديون\s+)?(?:بخصوص\s+)?(.+?)(?:\s+(?:لل|ل|الى\s+ال)(?:اولي|ديون\s+الاوليه|اوليه))?$/u', $t, $m)) {
            return new Command('transfer', subscriber: $m[1]);
        }
        // «شريت كيبل 30 متر سعر المتر 5 الاف وراوتر ب 40 الف» / «مصروف بنزين 10 الف»
        if (preg_match('/^(?:شريت|اشتريت|شرينا|مشتريات|صرفت|مصروف|مصاريف)\s+(.+)$/u', $t, $m) && ($items = self::items($m[1])) !== null) {
            return new Command('purchase', items: $items);
        }
        // «بعت راوتر ب 40 الف» / «بعت 2 راوتر ب 40 الف»
        if (preg_match('/^(?:بعت|بعنا|بيع|مبيعات)\s+(.+)$/u', $t, $m) && ($items = self::items($m[1])) !== null) {
            return new Command('sale', items: $items);
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
        foreach ([4, 3, 2, 1] as $n) {
            if (count($words) > $n && ($amount = self::amount(implode(' ', array_slice($words, -$n)))) !== null) {
                return [implode(' ', array_slice($words, 0, -$n)), $amount];
            }
        }

        return null;
    }

    /**
     * Items bought or sold, as written or spoken: «كيبل 30 متر سعر المتر 5 الاف وراوتر ب 40 الف».
     * Each item has a description and either a unit price with a quantity or a total.
     *
     * @return array<int, array{description: string, quantity: ?int, unit_price: ?int, total: ?int}>|null
     */
    public static function items(string $text): ?array
    {
        $segments = [[]];
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $current = &$segments[count($segments) - 1];
            $hasNumber = (bool) array_filter($current, fn ($w) => self::amount(preg_replace('/^ب/u', '', $w)) !== null || ctype_digit($w));
            // «وراوتر» / «و راوتر» after an item that already has its number starts the next item.
            if ($hasNumber && ($word === 'و' || (mb_substr($word, 0, 1) === 'و' && mb_strlen($word) > 2
                && self::wordsToNumber($word) === null && ! in_array(mb_substr($word, 1), ['الف', 'الاف'], true)))) {
                unset($current);
                $segments[] = $word === 'و' ? [] : [mb_substr($word, 1)];

                continue;
            }
            $current[] = $word;
            unset($current);
        }

        $items = [];
        foreach ($segments as $words) {
            if ($words === []) {
                continue;
            }
            $segment = implode(' ', $words);
            // «كيبل 30 متر سعر المتر 5 الاف»: a quantity and a unit price.
            if (preg_match('/^(.+?)\s+(?:سعر|بسعر|السعر)\s+(?:ال?(?:متر|قطعه|حبه|واحد|وحده|الوحده)\s+)?(.+)$/u', $segment, $m)
                && ($price = self::amount(preg_replace('/^ب/u', '', $m[2]))) !== null) {
                preg_match('/(\d+)\s*(?:متر|قطعه|قطع|حبه|حبات|لفه|عدد)?/u', $m[1], $q);
                $items[] = ['description' => $m[1], 'quantity' => isset($q[1]) ? (int) $q[1] : 1, 'unit_price' => $price, 'total' => null];

                continue;
            }
            // «راوتر ب 40 الف» / «بنزين 10 الف»: a total at the end.
            $found = null;
            foreach ([4, 3, 2, 1] as $n) {
                if (count($words) > $n) {
                    $tail = array_slice($words, -$n);
                    $tail[0] = preg_replace('/^ب(?=\S)/u', '', $tail[0]);
                    $tail = array_values(array_filter($tail, fn ($w) => $w !== 'ب'));
                    if ($tail !== [] && ($amount = self::amount(implode(' ', $tail))) !== null) {
                        $found = [implode(' ', array_slice($words, 0, -$n)), $amount];
                        break;
                    }
                }
            }
            if ($found === null || trim($found[0]) === '') {
                return null;
            }
            preg_match('/^(\d+)\s+/u', $found[0], $q);
            $items[] = ['description' => trim(preg_replace('/\s+ب$/u', '', $found[0])), 'quantity' => isset($q[1]) ? (int) $q[1] : null, 'unit_price' => null, 'total' => $found[1]];
        }

        return $items === [] ? null : $items;
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
        if (preg_match('/^(?:ل|لمده\s+)?(شهر|شهر كامل|اسبوع|اسبوعين|يومين)$/u', $t, $m)) {
            return ['شهر' => 30, 'شهر كامل' => 30, 'اسبوع' => 7, 'اسبوعين' => 14, 'يومين' => 2][$m[1]];
        }
        if (preg_match('/^(?:ل|لمده\s+)?(\d+|\S+(?:\s+\S+)?)\s+(?:يوم|ايام)$/u', $t, $m)) {
            $n = ctype_digit($m[1]) ? (int) $m[1] : (self::WORD_NUMBERS[$m[1]] ?? self::wordsToNumber($m[1]));

            return $n !== null && $n > 0 && $n <= 365 ? $n : null;
        }

        return null;
    }

    /** «35 الف», «35000», «35,000 دينار», «ربع مليون» → whole dinars; anything else → null. */
    public static function amount(string $text): ?int
    {
        // Thousands separators go before cleaning, which turns other commas into spaces.
        $t = self::clean(preg_replace('/(?<=\d)[,٬،](?=\d{3})/u', '', $text));
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

        return self::wordsToNumber($t);
    }

    /** Normalized Arabic with Western digits and no punctuation at the ends. */
    public static function clean(string $text): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        // Speech-to-text adds commas and full stops inside the sentence.
        // (A comma or point between digits stays: «35,000», «2.5».)
        $text = preg_replace('/(?<!\d)[،,.]|[،,.](?!\d)|[!?؟:؛"«»]+/u', ' ', $text);
        $text = Arabic::normalize($text);

        return preg_replace('/^[\s.!?؟،,]+|[\s.!?؟،,]+$/u', '', $text);
    }
}

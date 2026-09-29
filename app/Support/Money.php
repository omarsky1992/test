<?php

namespace App\Support;

class Money
{
    public static function format(?int $amount, bool $withCurrency = true): string
    {
        $formatted = number_format((int) $amount);

        return $withCurrency ? $formatted.' د.ع' : $formatted;
    }

    /**
     * Arabic words for a whole-dinar amount, e.g. 35000 → "خمسة وثلاثون ألف دينار عراقي".
     */
    public static function inWords(int $amount): string
    {
        if ($amount === 0) {
            return 'صفر دينار عراقي';
        }

        $scales = [
            1_000_000_000 => ['مليار', 'ملياران', 'مليارات'],
            1_000_000 => ['مليون', 'مليونان', 'ملايين'],
            1_000 => ['ألف', 'ألفان', 'آلاف'],
        ];

        $parts = [];
        $rest = $amount;
        foreach ($scales as $value => [$one, $two, $plural]) {
            $count = intdiv($rest, $value);
            $rest %= $value;
            if ($count === 0) {
                continue;
            }
            $parts[] = match (true) {
                $count === 1 => $one,
                $count === 2 => $two,
                $count <= 10 => self::below1000($count).' '.$plural,
                default => self::below1000($count).' '.$one,
            };
        }
        if ($rest > 0) {
            $parts[] = self::below1000($rest);
        }

        return implode(' و', $parts).' دينار عراقي';
    }

    private static function below1000(int $n): string
    {
        $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
            'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
        $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
        $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

        $parts = [];
        if ($n >= 100) {
            $parts[] = $hundreds[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n > 0 && $n < 20) {
            $parts[] = $ones[$n];
        } elseif ($n >= 20) {
            $unit = $n % 10;
            $parts[] = $unit ? $ones[$unit].' و'.$tens[intdiv($n, 10)] : $tens[intdiv($n, 10)];
        }

        return implode(' و', $parts);
    }
}

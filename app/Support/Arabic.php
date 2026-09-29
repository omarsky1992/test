<?php

namespace App\Support;

class Arabic
{
    /**
     * Normalizes Arabic text for searching: unifies alef/ya/ta-marbuta forms,
     * strips diacritics and tatweel, and collapses whitespace.
     */
    public static function normalize(?string $text): string
    {
        $text = (string) $text;
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
        ]);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /**
     * Normalizes an Iraqi mobile number to 9647XXXXXXXXX. Accepts 07…, 7…, +9647…, 009647…
     * and Arabic-Indic digits. Returns the digits unchanged when the format is not recognised.
     */
    public static function phone(?string $phone): string
    {
        $digits = strtr((string) $phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D/', '', $digits);

        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '9647') && strlen($digits) === 13) {
            return $digits;
        }
        if (str_starts_with($digits, '07') && strlen($digits) === 11) {
            return '964'.substr($digits, 1);
        }
        if (str_starts_with($digits, '7') && strlen($digits) === 10) {
            return '964'.$digits;
        }

        return $digits;
    }
}

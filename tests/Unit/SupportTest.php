<?php

namespace Tests\Unit;

use App\Support\Arabic;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            [35000, 'خمسة وثلاثون ألف دينار عراقي'],
            [20000, 'عشرون ألف دينار عراقي'],
            [100000, 'مائة ألف دينار عراقي'],
            [45250, 'خمسة وأربعون ألف ومائتان وخمسون دينار عراقي'],
            [3000, 'ثلاثة آلاف دينار عراقي'],
            [2000000, 'مليونان دينار عراقي'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amount_in_words(int $amount, string $words): void
    {
        $this->assertSame($words, Money::inWords($amount));
    }

    public function test_iraqi_phone_numbers_normalize_to_one_form(): void
    {
        foreach (['07701234567', '0770 123 4567', '+964 770 123 4567', '009647701234567', '7701234567', '٠٧٧٠١٢٣٤٥٦٧'] as $input) {
            $this->assertSame('9647701234567', Arabic::phone($input), $input);
        }
    }

    public function test_arabic_names_normalize_for_search(): void
    {
        $this->assertSame(Arabic::normalize('احمد'), Arabic::normalize('أحمد'));
        $this->assertSame(Arabic::normalize('فاطمه'), Arabic::normalize('فاطمة'));
        $this->assertSame(Arabic::normalize('علي'), Arabic::normalize('على'));
        $this->assertSame('مصطفي جاسم', Arabic::normalize('  مُصْطَفَى   جاسم '));
    }
}

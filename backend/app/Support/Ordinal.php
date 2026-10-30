<?php

namespace App\Support;

class Ordinal
{
    /**
     * @var array<int, string>
     */
    private const BANGLA_WORDS = [
        1 => '১ম', 2 => '২য়', 3 => '৩য়', 4 => '৪র্থ', 5 => '৫ম',
        6 => '৬ষ্ঠ', 7 => '৭ম', 8 => '৮ম', 9 => '৯ম', 10 => '১০ম',
    ];

    /**
     * @var array<string, string>
     */
    private const BANGLA_DIGITS = [
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    ];

    /**
     * "1st Chapter", "2nd Chapter", "3rd Chapter", "11th Chapter", ...
     */
    public static function englishChapter(int $n): string
    {
        $suffix = match (true) {
            in_array($n % 100, [11, 12, 13], true) => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return "{$n}{$suffix} Chapter";
    }

    /**
     * "১ম অধ্যায়", "২য় অধ্যায়", ..., "১১তম অধ্যায়", ...
     */
    public static function banglaChapter(int $n): string
    {
        $ordinal = self::BANGLA_WORDS[$n] ?? self::toBanglaDigits($n).'তম';

        return "{$ordinal} অধ্যায়";
    }

    private static function toBanglaDigits(int $n): string
    {
        return strtr((string) $n, self::BANGLA_DIGITS);
    }
}

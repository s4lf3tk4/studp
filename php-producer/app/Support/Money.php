<?php

namespace App\Support;

/** Внутри приложения деньги — целые копейки, наружу — «2 ₽» или «2,50 ₽» */
final class Money
{
    public static function rub(int $kopecks): string
    {
        $rubles = intdiv($kopecks, 100);
        $rest = $kopecks % 100;

        $formatted = number_format($rubles, 0, ',', ' ');

        if ($rest === 0) {
            return $formatted.' ₽';
        }

        return $formatted.','.str_pad((string) $rest, 2, '0', STR_PAD_LEFT).' ₽';
    }
}
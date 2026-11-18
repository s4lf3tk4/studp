<?php

namespace App\Support;

use App\Models\Campus;

/** Режим печати: ЧБ или цветной. Цена за страницу у каждого режима своя. */
final class PrintMode
{
    public const BW = 'bw';
    public const COLOR = 'color';

    /** Режим => подпись для бота и админки */
    public const LABELS = [
        self::BW => 'ЧБ',
        self::COLOR => 'Цветной',
    ];

    public static function label(?string $mode): ?string
    {
        return $mode === null ? null : (self::LABELS[$mode] ?? null);
    }

    public static function isMode(string $value): bool
    {
        return array_key_exists($value, self::LABELS);
    }

    /**
     * Доступные в корпусе режимы и их цена за страницу.
     * Режим с ценой 0 считается ненастроенным — бот его не предлагает.
     *
     * @return array<string, int> режим => копеек за страницу
     */
    public static function prices(?Campus $campus): array
    {
        if ($campus === null) {
            return [];
        }

        $prices = [];

        $black = (int) ($campus->price_per_page_kopecks ?? 0);
        $color = (int) ($campus->color_price_per_page_kopecks ?? 0);

        if ($black > 0) {
            $prices[self::BW] = $black;
        }

        if ($color > 0) {
            $prices[self::COLOR] = $color;
        }

        return $prices;
    }

    /** Цена режима в корпусе; null — режим недоступен */
    public static function priceFor(?Campus $campus, string $mode): ?int
    {
        return self::prices($campus)[$mode] ?? null;
    }
}
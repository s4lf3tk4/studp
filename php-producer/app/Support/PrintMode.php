<?php

namespace App\Support;

/** Режим печати: ЧБ или цветной. Цена за страницу зависит от режима и формата бумаги. */
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
}
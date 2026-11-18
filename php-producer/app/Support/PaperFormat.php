<?php

namespace App\Support;

/** Форматы бумаги: размеры в пунктах (1 pt = 1/72 дюйма) и распознавание формата страницы */
final class PaperFormat
{
    public const A4 = 'A4';
    public const A3 = 'A3';
    public const A2 = 'A2';
    public const A1 = 'A1';
    public const A0 = 'A0';

    /** Размер не совпал ни с одним печатаемым форматом */
    public const OTHER = 'OTHER';

    /** Печатаемые форматы по возрастанию площади: крупнее — дороже страница */
    public const ORDER = [self::A4, self::A3, self::A2, self::A1, self::A0];

    /** Размеры печатаемых листов в pt */
    private const SIZES = [
        self::A4 => [595.28, 841.89],
        self::A3 => [841.89, 1190.55],
        self::A2 => [1190.55, 1683.78],
        self::A1 => [1683.78, 2383.94],
        self::A0 => [2383.94, 3370.39],
    ];

    /** Допуск при сравнении, 6 pt ≈ 2 мм: в PDF страница часто чуть меньше номинала */
    private const TOLERANCE = 6.0;

    /**
     * Формат страницы: A4…A0 для печатаемых, OTHER для любого другого размера
     * (A5, A6, US Letter, визитка — всё это «не поддерживается»).
     */
    public static function fromPoints(float $width, float $height): string
    {
        $short = min($width, $height);
        $long = max($width, $height);

        foreach (self::SIZES as $format => [$paperShort, $paperLong]) {
            if (abs($short - $paperShort) <= self::TOLERANCE && abs($long - $paperLong) <= self::TOLERANCE) {
                return $format;
            }
        }

        return self::OTHER;
    }

    /** Размер листа в twips (DOCX хранит именно их) → формат */
    public static function fromTwips(float $width, float $height): string
    {
        return self::fromPoints($width / 20, $height / 20);
    }

    /** Формат можно напечатать в типографии */
    public static function isSupported(string $format): bool
    {
        return in_array($format, self::ORDER, true);
    }

    /** Список печатаемых форматов для сообщений: «A4, A3, A2, A1, A0» */
    public static function supportedLine(): string
    {
        return implode(', ', self::ORDER);
    }

    /**
     * «Формат => страниц»: печатаемые по возрастанию, нестандартный размер — в конце.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function sort(array $counts): array
    {
        $sorted = [];

        foreach (self::ORDER as $format) {
            $count = (int) ($counts[$format] ?? 0);

            if ($count > 0) {
                $sorted[$format] = $count;
            }
        }

        $other = (int) ($counts[self::OTHER] ?? 0);

        if ($other > 0) {
            $sorted[self::OTHER] = $other;
        }

        return $sorted;
    }
}
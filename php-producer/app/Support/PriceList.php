<?php

namespace App\Support;

use App\Models\Campus;

/**
 * Прайс корпуса: цена за страницу по паре (режим печати, формат бумаги).
 * Читается из колонок campuses.price_{mode}_{format}_kopecks; 0 — цена не задана.
 */
final class PriceList
{
    /** @param array<string, array<string, int>> $prices режим => формат => копеек */
    private function __construct(private readonly array $prices) {}

    public static function forCampus(?Campus $campus): self
    {
        if ($campus === null) {
            return new self([]);
        }

        $prices = [];

        foreach (array_keys(PrintMode::LABELS) as $mode) {
            foreach (PaperFormat::ORDER as $format) {
                $price = (int) ($campus->{self::column($mode, $format)} ?? 0);

                if ($price > 0) {
                    $prices[$mode][$format] = $price;
                }
            }
        }

        return new self($prices);
    }

    public static function column(string $mode, string $format): string
    {
        return 'price_'.$mode.'_'.strtolower($format).'_kopecks';
    }

    /** В корпусе нет ни одной цены */
    public function isEmpty(): bool
    {
        return $this->prices === [];
    }

    /** Цена режима за формат; null — не задана */
    public function price(string $mode, string $format): ?int
    {
        return $this->prices[$mode][$format] ?? null;
    }

    /**
     * Итого за документ в режиме; null — на какой-то формат цены нет.
     *
     * @param  array<string, int>  $formats  формат => страниц
     */
    public function total(string $mode, array $formats): ?int
    {
        $total = 0;

        foreach ($formats as $format => $pages) {
            $price = $this->price($mode, (string) $format);

            if ($price === null) {
                return null;
            }

            $total += $price * (int) $pages;
        }

        return $total;
    }

    /**
     * Режимы, которыми можно напечатать весь документ, и итог в каждом.
     *
     * @param  array<string, int>  $formats
     * @return array<string, int>  режим => копеек
     */
    public function totals(array $formats): array
    {
        $totals = [];

        foreach (array_keys(PrintMode::LABELS) as $mode) {
            $total = $this->total($mode, $formats);

            if ($total !== null) {
                $totals[$mode] = $total;
            }
        }

        return $totals;
    }

    /**
     * Форматы документа, на которые в этом режиме нет цены.
     *
     * @param  array<string, int>  $formats
     * @return array<int, string>
     */
    public function missing(string $mode, array $formats): array
    {
        $missing = [];

        foreach (array_keys($formats) as $format) {
            if ($this->price($mode, (string) $format) === null) {
                $missing[] = (string) $format;
            }
        }

        return $missing;
    }

    /**
     * Форматы, которые в корпусе не печатают ни ЧБ, ни цветом.
     *
     * @param  array<string, int>  $formats
     * @return array<int, string>
     */
    public function unpriced(array $formats): array
    {
        $unpriced = [];

        foreach (array_keys($formats) as $format) {
            $priced = false;

            foreach (array_keys(PrintMode::LABELS) as $mode) {
                if ($this->price($mode, (string) $format) !== null) {
                    $priced = true;

                    break;
                }
            }

            if (! $priced) {
                $unpriced[] = (string) $format;
            }
        }

        return $unpriced;
    }
}
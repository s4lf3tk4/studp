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
     * Цена заказа, где baseMode — режим по умолчанию, otherMode — режим для
     * страниц из $exceptions. null, если на какой-то формат нет цены.
     *
     * @param  array<int, string>  $pageFormats  номер страницы => формат
     * @param  array<int, int>  $exceptions  номера страниц другого режима
     */
    public function totalMixed(
        string $baseMode,
        string $otherMode,
        array $pageFormats,
        array $exceptions,
    ): ?int {
        $breakdown = $this->mixedBreakdown($baseMode, $otherMode, $pageFormats, $exceptions);

        return $breakdown === null ? null : $breakdown['total'];
    }

    /**
     * Разбивка по форматам и режимам — для текста клиенту.
     *
     * @param  array<int, string>  $pageFormats
     * @param  array<int, int>  $exceptions
     * @return array{groups: array<int, array{format: string, pages: int, price: int, exception: bool}>, total: int}|null
     */
    public function mixedBreakdown(
        string $baseMode,
        string $otherMode,
        array $pageFormats,
        array $exceptions,
    ): ?array {
        if ($pageFormats === []) {
            return null;
        }

        $exceptionSet = array_flip(array_map('intval', $exceptions));
        $groups = [];
        $total = 0;

        foreach ($pageFormats as $page => $format) {
            $isException = isset($exceptionSet[(int) $page]);
            $mode = $isException ? $otherMode : $baseMode;
            $price = $this->price($mode, (string) $format);

            if ($price === null) {
                return null;
            }

            $key = $format.'|'.($isException ? '1' : '0');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'format' => (string) $format,
                    'pages' => 0,
                    'price' => $price,
                    'exception' => $isException,
                ];
            }

            $groups[$key]['pages']++;
            $total += $price;
        }

        return ['groups' => array_values($groups), 'total' => $total];
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
     * @param <string, int>  $formats
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
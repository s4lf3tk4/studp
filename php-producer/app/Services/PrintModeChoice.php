<?php

namespace App\Services;

use App\Models\Campus;
use App\Support\BotTexts;
use App\Support\PriceList;
use App\Support\PrintMode;

/**
 * Выбор принтера после подсчёта страниц: ЧБ или цветной.
 * Цена зависит от формата каждой страницы и берётся из прайса корпуса.
 */
final class PrintModeChoice
{
    public const STAGE_CONFIRM = 'confirm';
    public const STAGE_MODE = 'mode';
    public const STAGE_RESTART = 'restart';

    public function __construct(private readonly DocumentStore $store) {}

    /**
     * @return array{text: string, stage: string}
     */
    public function choose(string $platform, int $userId, string $mode): array
    {
        $draft = $this->store->get($platform, $userId);
        $formats = isset($draft['pages_by_format']) && is_array($draft['pages_by_format'])
            ? $draft['pages_by_format']
            : [];

        if ($draft === null || $formats === []) {
            return ['text' => BotTexts::orderNotFound(), 'stage' => self::STAGE_RESTART];
        }

        // Заказ уже подтверждён: сумму в выставленном счёте не меняем
        if (isset($draft['document_id'])) {
            $url = (string) ($draft['payment_url'] ?? '');

            return [
                'text' => BotTexts::alreadyConfirmed((int) $draft['document_id'], $url !== '' ? $url : null),
                'stage' => self::STAGE_RESTART,
            ];
        }

        $campus = isset($draft['campus'])
            ? Campus::query()->where('code', (string) $draft['campus'])->first()
            : null;

        $priceList = PriceList::forCampus($campus);
        $total = $priceList->total($mode, $formats);

        if ($total === null) {
            return [
                'text' => BotTexts::modeUnavailable(
                    PrintMode::label($mode) ?? $mode,
                    $priceList->missing($mode, $formats),
                ),
                'stage' => self::STAGE_MODE,
            ];
        }

        $prices = [];

        foreach (array_keys($formats) as $format) {
            $prices[(string) $format] = (int) $priceList->price($mode, (string) $format);
        }

        $this->store->mode($platform, $userId, $mode, $prices, $total);

        return [
            'text' => BotTexts::modeConfirmed(
                PrintMode::label($mode) ?? $mode,
                $formats,
                $prices,
                $total,
                str_ends_with((string) ($draft['file_path'] ?? ''), '.docx'),
            ),
            'stage' => self::STAGE_CONFIRM,
        ];
    }
}
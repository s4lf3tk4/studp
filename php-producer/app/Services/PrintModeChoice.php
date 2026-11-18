<?php

namespace App\Services;

use App\Models\Campus;
use App\Support\BotTexts;
use App\Support\PrintMode;

/**
 * Выбор принтера после подсчёта страниц: ЧБ или цветной.
 * Цена берётся из корпуса и сразу попадает в черновик заказа.
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

        if ($draft === null || ! isset($draft['pages'])) {
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

        $price = PrintMode::priceFor($campus, $mode);

        if ($price === null) {
            // В корпусе вообще нет цен — предлагать нечего, сразу к подтверждению
            $stage = PrintMode::prices($campus) === [] ? self::STAGE_CONFIRM : self::STAGE_MODE;

            return [
                'text' => BotTexts::modeUnavailable(PrintMode::label($mode) ?? $mode),
                'stage' => $stage,
            ];
        }

        $this->store->mode($platform, $userId, $mode, $price);

        return [
            'text' => BotTexts::modeConfirmed(
                PrintMode::label($mode) ?? $mode,
                (int) $draft['pages'],
                $price,
                str_ends_with((string) ($draft['file_path'] ?? ''), '.docx'),
            ),
            'stage' => self::STAGE_CONFIRM,
        ];
    }
}
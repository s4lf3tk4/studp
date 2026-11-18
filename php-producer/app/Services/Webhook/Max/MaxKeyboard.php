<?php

namespace App\Services\Webhook\Max;

use App\Models\Campus;
use App\Services\Webhook\BotCommand;
use Illuminate\Support\Facades\Log;

final class MaxKeyboard
{
    /** MAX разрешает до 7 кнопок в ряду для callback */
    private const PER_ROW = 2;

    private const MARK_ACTIVE = '🟢 ';
    private const MARK_INACTIVE = '🔴 ';

    /** Кнопки корпусов: выключенный корпус помечаем маркером */
    /** Кнопки корпусов: состав берётся из БД, кода в списке нет */
    public static function chosenCampus(): ?array
    {
        $campuses = self::campuses();

        if ($campuses === []) {
            return null;
        }

        $buttons = [];

        foreach ($campuses as $code => $campus) {
            $buttons[] = [
                'type' => 'callback',
                'text' => ($campus['active'] ? self::MARK_ACTIVE : self::MARK_INACTIVE).$campus['label'],
                'payload' => json_encode(['command' => $code], JSON_UNESCAPED_UNICODE),
            ];
        }

        return self::keyboard($buttons);
    }

    public static function cancelBtn(): array
    {
        return self::keyboard([[
            'type' => 'callback',
            'text' => 'Отмена',
            'payload' => json_encode(['command' => BotCommand::CANCEL], JSON_UNESCAPED_UNICODE),
        ]]);
    }

    /** Выбор принтера: у ЧБ и цветного разная цена за страницу */
    public static function printMode(): array
    {
        return self::keyboard([
            [
                'type' => 'callback',
                'text' => 'ЧБ',
                'payload' => json_encode(['command' => BotCommand::PRINT_BW], JSON_UNESCAPED_UNICODE),
            ],
            [
                'type' => 'callback',
                'text' => 'Цветной',
                'payload' => json_encode(['command' => BotCommand::PRINT_COLOR], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    /** Кнопки решения по заказу: подтвердить оплату или отменить файл */
    public static function confirmOrCancel(): array
    {
        return self::keyboard([
            [
                'type' => 'callback',
                'text' => 'Подтвердить',
                'payload' => json_encode(['command' => BotCommand::CONFIRM], JSON_UNESCAPED_UNICODE),
            ],
            [
                'type' => 'callback',
                'text' => 'Отмена',
                'payload' => json_encode(['command' => BotCommand::CANCEL], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $buttons
     */
    private static function keyboard(array $buttons): array
    {
        return [
            'type' => 'inline_keyboard',
            'payload' => ['buttons' => array_chunk($buttons, self::PER_ROW)],
        ];
    }

    /** @return array<string, array{label: string, active: bool}> */
    private static function campuses(): array
    {
        try {
            $campuses = [];

            foreach (Campus::query()->orderBy('id')->get() as $campus) {
                $campuses[(string) $campus->code] = [
                    'label' => (string) $campus->title,
                    'active' => (bool) $campus->is_active,
                ];
            }

            return $campuses;
        } catch (\Throwable $e) {
            Log::error('Не прочитал корпуса для клавиатуры: '.$e->getMessage());

            return [];
        }
    }
}
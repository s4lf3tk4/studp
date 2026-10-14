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
    public static function chosenCampus(): array
    {
        $buttons = [];

        foreach (self::campuses() as $code => $campus) {
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
            $rows = Campus::query()->orderBy('id')->get();

            if ($rows->isNotEmpty()) {
                $campuses = [];

                foreach ($rows as $campus) {
                    $campuses[(string) $campus->code] = [
                        'label' => (string) $campus->title,
                        'active' => (bool) $campus->is_active,
                    ];
                }

                return $campuses;
            }
        } catch (\Throwable $e) {
            Log::warning('Не прочитал корпуса для клавиатуры: '.$e->getMessage());
        }

        // БД пуста или недоступна — берём константы, чтобы бот не остался без кнопок
        return array_map(
            fn (string $label): array => ['label' => $label, 'active' => true],
            BotCommand::CAMPUSES,
        );
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
}
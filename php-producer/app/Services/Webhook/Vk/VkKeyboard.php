<?php

namespace App\Services\Webhook\Vk;

use App\Models\Campus;
use App\Services\Webhook\BotCommand;
use Illuminate\Support\Facades\Log;

final class VkKeyboard
{
    /** VK разрешает не больше 5 кнопок в ряду */
    private const PER_ROW = 5;

    /** Кнопки корпусов: цвет зависит от campuses.is_active */
    public static function chosenCampus(): array
    {
        $buttons = [];

        foreach (self::campuses() as $code => $campus) {
            $buttons[] = [
                'action' => [
                    'type' => 'text',
                    'label' => $campus['label'],
                    'payload' => json_encode(['command' => $code], JSON_UNESCAPED_UNICODE),
                ],
                // красная кнопка — корпус выключен (is_active = false)
                'color' => $campus['active'] ? 'primary' : 'negative',
            ];
        }

        return [
            'one_time' => false,
            'buttons' => array_chunk($buttons, self::PER_ROW),
        ];
    }

    public static function cancelBtn(): array
    {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Отмена',
                            'payload' => json_encode(['command' => BotCommand::CANCEL], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'negative',
                    ],
                ],
            ],
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
}
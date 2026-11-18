<?php

namespace App\Services\Webhook\Vk;

use App\Models\Campus;
use App\Services\Webhook\BotCommand;
use Illuminate\Support\Facades\Log;

final class VkKeyboard
{
    /** VK разрешает не больше 5 кнопок в ряду */
    private const PER_ROW = 5;

    /** Главное меню: корпуса из БД + вход в режим оператора */
    public static function chosenCampus(): ?array
    {
        $campuses = self::campuses();

        if ($campuses === []) {
            return null;   // корпуса не заведены — отправим текст без кнопок
        }

        $buttons = [];

        foreach ($campuses as $code => $campus) {
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

        $rows = array_chunk($buttons, self::PER_ROW);

        // Внизу всегда есть переход к живому оператору — отдельным рядом
        $rows[] = [
            [
                'action' => [
                    'type' => 'text',
                    'label' => 'Оператор',
                    'payload' => json_encode(['command' => BotCommand::CHAT_OPERATOR], JSON_UNESCAPED_UNICODE),
                ],
                'color' => 'secondary',
            ],
        ];

        return [
            'one_time' => false,
            'buttons' => $rows,
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

    /** Режим оператора: единственная кнопка — возврат к боту, всегда на месте */
    public static function backToBot(): array
    {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Вернуться к боту',
                            'payload' => json_encode(['command' => BotCommand::CHAT_BOT], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'primary',
                    ],
                ],
            ],
        ];
    }

    /** Выбор принтера: у ЧБ и цветного разная цена за страницу */
    public static function printMode(): array
    {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'ЧБ',
                            'payload' => json_encode(['command' => BotCommand::PRINT_BW], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'primary',
                    ],
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Цветной',
                            'payload' => json_encode(['command' => BotCommand::PRINT_COLOR], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'positive',
                    ],
                ],
            ],
        ];
    }

    /** Кнопки решения по заказу: подтвердить оплату или отменить файл */
    public static function confirmOrCancel(): array
    {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Подтвердить',
                            'payload' => json_encode(['command' => BotCommand::CONFIRM], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'positive',
                    ],
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
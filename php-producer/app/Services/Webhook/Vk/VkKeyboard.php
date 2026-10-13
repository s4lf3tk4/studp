<?php

namespace App\Services\Webhook\Vk;

use App\Services\Webhook\BotCommand;

final class VkKeyboard
{
    /** Кнопки выбора корпуса: «1 корпус», «2 корпус», «3 корпус», «4 корпус» */
    public static function chosenCampus(): array
    {
        $buttons = [];

        for ($korpus = 1; $korpus <= 4; $korpus++) {
            $buttons[] = [
                [
                    'action' => [
                        'type' => 'text',
                        'label' => $korpus . ' корпус',
                        'payload' => json_encode(
                            [
                                'command' => BotCommand::SEND_FILE,
                                'korpus' => $korpus,
                            ],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ],
                    'color' => 'primary',
                ],
            ];
        }

        return [
            'one_time' => false,
            'buttons' => $buttons,
        ];
    }
}
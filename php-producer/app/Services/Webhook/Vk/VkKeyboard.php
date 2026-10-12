<?php

namespace App\Services\Webhook\Vk;

use App\Services\Webhook\BotCommand;

final class VkKeyboard
{
    /** Кнопка «Отправить файл» — обычная текстовая, payload уходит вместе с сообщением */
    public static function sendFile(): array
    {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Отправить файл',
                            'payload' => json_encode(['command' => BotCommand::SEND_FILE], JSON_UNESCAPED_UNICODE),
                        ],
                        'color' => 'primary',
                    ],
                ],
            ],
        ];
    }
}
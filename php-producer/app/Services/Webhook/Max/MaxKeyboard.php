<?php

namespace App\Services\Webhook\Max;

final class MaxKeyboard
{
    /**
     * Кнопка «Отправить файл» типа message: нажатие приходит обычным сообщением
     * с текстом кнопки — payload разбирать не нужно.
     */
    public static function sendFile(): array
    {
        return [
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [
                    [
                        ['type' => 'message', 'text' => 'Отправить файл'],
                    ],
                ],
            ],
        ];
    }
}
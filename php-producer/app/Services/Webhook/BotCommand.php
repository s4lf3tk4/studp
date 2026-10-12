<?php

namespace App\Services\Webhook;

final class BotCommand
{
    public const START = 'start';
    public const SEND_FILE = 'send_file';

    /** Подписи кнопок: VK при нажатии текстовой кнопки присылает её текст */
    private const LABELS = [
        'начать' => self::START,
        '/start' => self::START,
        'отправить файл' => self::SEND_FILE,
    ];

    /** Команда из payload кнопки или из текста сообщения */
    public static function detect(array $message, ?string $text): ?string
    {
        $payload = $message['payload'] ?? null;

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : ['command' => $payload];
        }

        $command = is_array($payload) ? ($payload['command'] ?? null) : null;

        if (is_string($command) && $command !== '') {
            return $command;
        }

        return self::LABELS[mb_strtolower(trim((string) $text))] ?? null;
    }

    /** Команда из payload callback-кнопки MAX */
    public static function fromCallback(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) && isset($decoded['command'])
            ? (string) $decoded['command']
            : $payload;
    }
}
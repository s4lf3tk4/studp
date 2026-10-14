<?php

namespace App\Services\Webhook;

final class BotCommand
{
    public const START = 'start';
    public const SEND_FILE = 'send_file';
    public const CAMPUS1 = 'campus1';
    public const CAMPUS2 = 'campus2';
    public const CAMPUS3 = 'campus3';
    public const CAMPUS4 = 'campus4';
    public const CANCEL = 'cancel';

    /** Команда => подпись кнопки: единственное место, где перечислены корпуса */
    public const CAMPUSES = [
        self::CAMPUS1 => 'Корпус 1',
        self::CAMPUS2 => 'Корпус 2',
        self::CAMPUS3 => 'Корпус 3',
        self::CAMPUS4 => 'Корпус 4',
    ];

    /** Подписи кнопок: VK при нажатии текстовой кнопки присылает её текст (в нижнем регистре) */
    private const LABELS = [
        'начать' => self::START,
        '/start' => self::START,
        'отправить файл' => self::SEND_FILE,
        'корпус 1' => self::CAMPUS1,
        'корпус 2' => self::CAMPUS2,
        'корпус 3' => self::CAMPUS3,
        'корпус 4' => self::CAMPUS4,
        'отмена'=> self::CANCEL,
    ];

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

    public static function isCampus(string $command): bool
    {
        return array_key_exists($command, self::CAMPUSES);
    }
}
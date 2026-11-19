<?php

namespace App\Services\Webhook;

use App\Support\PrintMode;

final class BotCommand
{
    public const START = 'start';
    public const SEND_FILE = 'send_file';
    public const CANCEL = 'cancel';
    public const CONFIRM = 'confirm';
    public const PRINT_BW = 'print_bw';
    public const PRINT_COLOR = 'print_color';
    public const CHAT_OPERATOR = 'chat_operator';
    public const CHAT_BOT = 'chat_bot';
    public const PHOTOS_DONE = 'photos_done';
    public const PHOTOS_CANCEL = 'photos_cancel';

    /** Команды кнопок режима печати => режим в заказе */
    public const MODES = [
        self::PRINT_BW => PrintMode::BW,
        self::PRINT_COLOR => PrintMode::COLOR,
    ];

    /**
     * Подписи текстовых команд. Корпусов здесь нет: они живут в БД,
     * а «Корпус 2» текстом разбирается шаблоном ниже.
     */
    private const LABELS = [
        'начать' => self::START,
        '/start' => self::START,
        'отправить файл' => self::SEND_FILE,
        'отмена' => self::CANCEL,
        'подтвердить' => self::CONFIRM,
        'чб' => self::PRINT_BW,
        'ч/б' => self::PRINT_BW,
        'чёрно-белый' => self::PRINT_BW,
        'черно-белый' => self::PRINT_BW,
        'цветной' => self::PRINT_COLOR,
        'цветная' => self::PRINT_COLOR,
        'цвет' => self::PRINT_COLOR,
        'оператор' => self::CHAT_OPERATOR,
        'связаться с оператором' => self::CHAT_OPERATOR,
        'к боту' => self::CHAT_BOT,
        'вернуться к боту' => self::CHAT_BOT,
        'бот' => self::CHAT_BOT,
        'подтвердить всё' => self::PHOTOS_DONE,
        'удалить всё' => self::PHOTOS_CANCEL,
    ];

    /** «Корпус 2» текстом → campus2: код одинаковый для клавиатуры и для БД */
    private const CAMPUS_PATTERN = '/^корпус\s*(\d{1,3})$/u';

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

        $text = mb_strtolower(trim((string) $text));

        if (isset(self::LABELS[$text])) {
            return self::LABELS[$text];
        }

        if (preg_match(self::CAMPUS_PATTERN, $text, $matches) === 1) {
            return 'campus'.$matches[1];
        }

        return null;
    }

    /** Решение по собранным фото: подтвердить или удалить */
    public static function isPhotoDecision(string $command): bool
    {
        return in_array($command, [self::PHOTOS_DONE, self::PHOTOS_CANCEL], true);
    }

    /**
     * Похоже ли на код корпуса. Существует ли такой корпус — знает только БД,
     * поэтому хендлер сразу делает запрос и на пустой ответ говорит «не понял».
     */
    public static function isCampus(string $command): bool
    {
        return preg_match('/^campus\d{1,3}$/', $command) === 1;
    }

    public static function isMode(string $command): bool
    {
        return isset(self::MODES[$command]);
    }

    /** Команда кнопки => режим печати (bw | color) */
    public static function mode(string $command): ?string
    {
        return self::MODES[$command] ?? null;
    }

    /** Переключение между ботом и оператором: работает в любом состоянии */
    public static function isChatSwitch(string $command): bool
    {
        return in_array($command, [self::CHAT_OPERATOR, self::CHAT_BOT], true);
    }
}
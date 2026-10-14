<?php

namespace App\Services\Webhook\Max;

final readonly class MaxEvent
{
    /** @param array<string, mixed>|null $file */
    public function __construct(
        public string $type,
        public ?string $text,
        public ?int $userId,
        public ?int $chatId,
        public ?string $senderName,
        public ?array $file,
        public ?string $callbackId,
        public ?string $command,
        public array $raw,
        public string $eventKey,
    ) {}

    public static function fromPayload(array $payload): self
    {
        $type = (string) ($payload['update_type'] ?? '');
        $message = $payload['message'] ?? [];
        $body = $message['body'] ?? [];
        $sender = $message['sender'] ?? [];

        // Нажатие inline-кнопки: update_type = message_callback
        $callback = is_array($payload['callback'] ?? null) ? $payload['callback'] : [];
        $callbackId = $callback['callback_id'] ?? null;
        $hasCallback = is_string($callbackId) && $callbackId !== '';

        // payload кнопки: строка JSON (реже — массив)
        $command = self::commandFromPayload($callback['payload'] ?? null);

        // Файл приходит вложением с type = file
        $file = null;

        foreach ($body['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) === 'file') {
                $file = $attachment;
                break;
            }
        }

        // Кто нажал/написал. В message_callback поле message — это сообщение БОТА,
        // поэтому настоящий пользователь берётся из callback.user, иначе выбор
        // корпуса сохранится на id бота и файл потом «не найдёт» корпус
        $actor = $hasCallback
            ? ($callback['user'] ?? $payload['user'] ?? [])
            : ($sender ?: ($payload['user'] ?? []));

        $userId = $actor['user_id'] ?? null;

        $senderName = $actor['name'] ?? null;

        if (! is_string($senderName) || $senderName === '') {
            $senderName = trim(($actor['first_name'] ?? '').' '.($actor['last_name'] ?? ''));
        }

        // Текст сообщения бота — не реплика пользователя
        $text = $hasCallback ? null : ($body['text'] ?? null);

        // MAX повторяет доставку до 10 раз. Для нажатия ключ — callback_id: он уникален
        // для каждого нажатия, а mid у всех кнопок одного сообщения одинаковый
        $mid = $body['mid'] ?? null;

        if ($hasCallback) {
            $eventKey = 'max:cb:'.$callbackId;
        } elseif (is_string($mid) || is_int($mid)) {
            $eventKey = 'max:mid:'.$mid;
        } else {
            $eventKey = 'max:'.$type.'@'.($payload['timestamp'] ?? '');
        }
        
        $chatId = $payload['chat_id'] ?? $message['recipient']['chat_id'] ?? null;
        
        return new self(
            type: $type,
            text: is_string($text) ? $text : null,
            userId: is_int($userId) ? $userId : null,
            chatId: is_int($payload['chat_id'] ?? null) ? $payload['chat_id'] : null,
            senderName: is_string($senderName) && $senderName !== '' ? $senderName : null,
            file: $file,
            callbackId: $hasCallback ? $callbackId : null,
            command: $command,
            raw: $payload,
            eventKey: $eventKey,
        );
    }

    /** payload кнопки приходит строкой JSON (реже — массивом) */
    private static function commandFromPayload(mixed $payload): ?string
    {
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        $command = is_array($payload) ? ($payload['command'] ?? null) : null;

        return is_string($command) && $command !== '' ? $command : null;
    }
}
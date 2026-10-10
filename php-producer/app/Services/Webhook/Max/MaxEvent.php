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
        public array $raw,
        public string $eventKey,
    ) {}

    public static function fromPayload(array $payload): self
    {
        $type = (string) ($payload['update_type'] ?? '');
        $message = $payload['message'] ?? [];
        $body = $message['body'] ?? [];
        $sender = $message['sender'] ?? [];

        $file = null;

        foreach ($body['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) === 'file') {
                $file = $attachment;
                break;
            }
        }

        // MAX повторяет доставку до 10 раз: ключ — id сообщения
        $mid = $body['mid'] ?? null;
        $eventKey = 'max:'.(is_string($mid) || is_int($mid) ? $mid : $type.'@'.($payload['timestamp'] ?? ''));

        return new self(
            type: $type,
            text: is_string($body['text'] ?? null) ? $body['text'] : null,
            userId: is_int($sender['user_id'] ?? null) ? $sender['user_id'] : null,
            chatId: is_int($payload['chat_id'] ?? null) ? $payload['chat_id'] : null,
            senderName: $sender['name'] ?? null,
            file: $file,
            raw: $payload,
            eventKey: $eventKey,
        );
    }
}
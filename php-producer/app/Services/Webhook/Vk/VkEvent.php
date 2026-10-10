<?php

namespace App\Services\Webhook\Vk;

final readonly class VkEvent
{
    /** @param array<string, mixed>|null $doc */
    public function __construct(
        public string $type,
        public string $eventId,
        public ?string $text,
        public ?int $peerId,
        public ?int $userId,
        public ?array $doc,
        public array $message,
    ) {}

    public static function fromPayload(array $payload): self
    {
        $message = $payload['object']['message'] ?? [];

        $doc = null;

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) === 'doc' && is_array($attachment['doc'] ?? null)) {
                $doc = $attachment['doc'];
                break;
            }
        }

        return new self(
            type: (string) ($payload['type'] ?? ''),
            eventId: (string) ($payload['event_id'] ?? ''),
            text: is_string($message['text'] ?? null) ? $message['text'] : null,
            peerId: is_int($message['peer_id'] ?? null) ? $message['peer_id'] : null,
            userId: is_int($message['from_id'] ?? null) ? $message['from_id'] : null,
            doc: $doc,
            message: $message,
        );
    }
}
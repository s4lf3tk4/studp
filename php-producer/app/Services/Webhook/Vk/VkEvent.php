<?php

namespace App\Services\Webhook\Vk;

use App\Support\VkPhoto;

final readonly class VkEvent
{
    /**
     * @param  array<string, mixed>|null  $doc
     * @param  array<int, string>  $photos
     */
    public function __construct(
        public string $type,
        public string $eventId,
        public ?string $text,
        public ?int $peerId,
        public ?int $userId,
        public ?array $doc,
        public array $photos,
        public int $date,
        public int $messageId,
        public int $groupId,
        public array $message,
    ) {}

    public static function fromPayload(array $payload): self
    {
        $message = $payload['object']['message'] ?? [];

        $doc = null;
        $photos = [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            $type = $attachment['type'] ?? null;

            if ($type === 'doc' && $doc === null && is_array($attachment['doc'] ?? null)) {
                $doc = $attachment['doc'];
            }

            if ($type === 'photo') {
                $url = VkPhoto::largestUrl($attachment['photo'] ?? null);

                if ($url !== null) {
                    $photos[] = $url;
                }
            }
        }

        return new self(
            type: (string) ($payload['type'] ?? ''),
            eventId: (string) ($payload['event_id'] ?? ''),
            text: is_string($message['text'] ?? null) ? $message['text'] : null,
            peerId: is_int($message['peer_id'] ?? null) ? $message['peer_id'] : null,
            userId: is_int($message['from_id'] ?? null) ? $message['from_id'] : null,
            doc: $doc,
            photos: $photos,
            date: (int) ($message['date'] ?? 0),
            messageId: (int) ($message['id'] ?? 0),
            groupId: (int) ($payload['group_id'] ?? 0),
            message: $message,
        );
    }
}
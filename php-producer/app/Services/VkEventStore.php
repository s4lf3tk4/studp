<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Временное хранилище событий VK — защита от повторной доставки.
 *
 * VK повторяет событие, если не получил "ok": через 10 с, 3 мин, 10 мин,
 * 30 мин и 1 час, поэтому event_id занимаем ДО обработки и атомарно.
 */
final class VkEventStore
{
    private const TTL = 86400; // сутки с запасом перекрывают все повторы

    /**
     * true — событие новое, false — уже приходило.
     *
     * @param  array<string, mixed>  $payload
     */
    public function claim(string $eventId, array $payload): bool
    {
        // Cache::add в Redis — это SET NX: при одновременных повторах
        // событие займёт ровно один запрос
        return Cache::add($this->key($eventId), $payload, self::TTL);
    }

    /** @return array<string, mixed>|null */
    public function payload(string $eventId): ?array
    {
        $stored = Cache::get($this->key($eventId));

        return is_array($stored) ? $stored : null;
    }

    public function forget(string $eventId): void
    {
        Cache::forget($this->key($eventId));
    }

    private function key(string $eventId): string
    {
        return "vk:event:{$eventId}";
    }
}

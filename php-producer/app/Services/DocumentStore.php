<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Ссылка на присланный файл: нужна после подсчёта страниц — для счёта,
 * печати и повторного скачивания, когда апдейт VK уже не придёт.
 */
final class DocumentStore
{
    private const TTL = 604800; // 7 суток: окно заказа переживает оплату и печать

    /**
     * @param  array<string, mixed>  $doc  id, owner_id, title, ext, size, url
     */
    public function remember(
        string $platform,
        int $userId,
        int $recipientId,
        array $doc,
        ?string $campus = null,
    ): void {
        Cache::put($this->key($platform, $userId), [
            'platform' => $platform,
            'user_id' => $userId,
            'recipient_id' => $recipientId, // куда отвечать: в беседе это peer_id, а не user_id
            'url' => (string) ($doc['url'] ?? ''),
            'title' => $doc['title'] ?? null,
            'ext' => strtolower((string) ($doc['ext'] ?? '')),
            'size' => isset($doc['size']) ? (int) $doc['size'] : null,
            'doc_id' => isset($doc['id']) ? (int) $doc['id'] : null,
            'owner_id' => isset($doc['owner_id']) ? (int) $doc['owner_id'] : null,
            'campus' => $campus,
            'pages' => null,
            'received_at' => now()->toIso8601String(),
        ], self::TTL);
    }

    /** @return array<string, mixed>|null */
    public function get(string $platform, int $userId): ?array
    {
        $stored = Cache::get($this->key($platform, $userId));

        return is_array($stored) ? $stored : null;
    }

    /** Дописать число страниц, когда джоба его посчитала */
    public function pages(string $platform, int $userId, int $pages): void
    {
        $entry = $this->get($platform, $userId);

        if ($entry === null) {
            return;
        }

        $entry['pages'] = $pages;
        $entry['counted_at'] = now()->toIso8601String();

        // Остаток TTL не сохраняем — запись и так живёт в пределах окна заказа
        Cache::put($this->key($platform, $userId), $entry, self::TTL);
    }

    public function forget(string $platform, int $userId): void
    {
        Cache::forget($this->key($platform, $userId));
    }

    private function key(string $platform, int $userId): string
    {
        return "file:{$platform}:{$userId}";
    }
}
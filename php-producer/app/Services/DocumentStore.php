<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Черновик заказа: присланный файл, выбранный корпус, число страниц и сумма.
 * Живёт до оплаты; дальше источник истины — таблицы documents/payments/receipts.
 */
final class DocumentStore
{
    private const TTL = 604800; // 7 суток: окно заказа переживает оплату и печать

    /**
     * @param  array<string, mixed>  $doc  url, title (лишние ключи из апдейта игнорируются)
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
            'campus' => $campus,
            'file_path' => null,
            'pages' => null,
        ], self::TTL);
    }

    /** @return array<string, mixed>|null */
    public function get(string $platform, int $userId): ?array
    {
        $stored = Cache::get($this->key($platform, $userId));

        return is_array($stored) ? $stored : null;
    }

    /** Путь к скачанному файлу — джоба узнаёт его после сохранения */
    public function file(string $platform, int $userId, string $path): void
    {
        $this->put($platform, $userId, ['file_path' => $path]);
    }

    /** Число страниц и цена корпуса на момент заказа */
    public function pages(
        string $platform,
        int $userId,
        int $pages,
        ?int $pricePerPageKopecks = null,
    ): void {
        $this->put($platform, $userId, [
            'pages' => $pages,
            'price_per_page_kopecks' => $pricePerPageKopecks,
            'amount_kopecks' => $pricePerPageKopecks !== null ? $pages * $pricePerPageKopecks : null,
            'counted_at' => now()->toIso8601String(),
        ]);
    }

    public function forget(string $platform, int $userId): void
    {
        Cache::forget($this->key($platform, $userId));
    }

    /** @param  array<string, mixed>  $values */
    private function put(string $platform, int $userId, array $values): void
    {
        $entry = $this->get($platform, $userId);

        if ($entry === null) {
            return;
        }

        Cache::put($this->key($platform, $userId), array_merge($entry, $values), self::TTL);
    }

    private function key(string $platform, int $userId): string
    {
        return "file:{$platform}:{$userId}";
    }
}
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Черновик заказа между отправкой файла и подтверждением: путь к файлу,
 * корпус, страницы, режим печати и сумма. Живёт в Redis; источник истины — documents/payments.
 */
final class DocumentStore
{
    /** Неоплаченный черновик живёт сутки, подтверждённый заказ — 7 суток */
    private const TTL_UNPAID = 86400;
    private const TTL_PAID = 604800;

    /**
     * @param  array<string, mixed>  $doc  url, title
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
            'recipient_id' => $recipientId,   // куда отвечать: в беседе это peer_id, а не user_id
            'url' => (string) ($doc['url'] ?? ''),
            'title' => $doc['title'] ?? null,
            'campus' => $campus,
            'file_path' => null,
            'size' => null,
            'pages' => null,
            'print_mode' => null,             // bw | color — выберет клиент после подсчёта
            'status' => 'new',
            'created_at' => now()->toIso8601String(),
        ], self::TTL_UNPAID);
    }

    /** @return array<string, mixed>|null */
    public function get(string $platform, int $userId): ?array
    {
        $stored = Cache::get($this->key($platform, $userId));

        return is_array($stored) ? $stored : null;
    }

    /** Путь к скачанному файлу и его размер — джоба узнаёт их после сохранения */
    public function file(string $platform, int $userId, string $path, ?int $size = null): void
    {
        $this->put($platform, $userId, ['file_path' => $path, 'size' => $size]);
    }

    /** Число страниц; прошлый выбор режима сбрасывается — он относился к другому файлу */
    public function pages(
        string $platform,
        int $userId,
        int $pages,
        ?int $pricePerPageKopecks = null,
    ): void {
        $this->put($platform, $userId, [
            'pages' => $pages,
            'print_mode' => null,
            'price_per_page_kopecks' => $pricePerPageKopecks,
            'amount_kopecks' => $pricePerPageKopecks !== null ? $pages * $pricePerPageKopecks : null,
            'status' => 'counted',
            'counted_at' => now()->toIso8601String(),
        ]);
    }

    /** Режим печати выбран: цена за страницу и сумма пересчитываются под него */
    public function mode(string $platform, int $userId, string $mode, int $pricePerPageKopecks): void
    {
        $entry = $this->get($platform, $userId);
        $pages = isset($entry['pages']) ? (int) $entry['pages'] : null;

        $this->put($platform, $userId, [
            'print_mode' => $mode,
            'price_per_page_kopecks' => $pricePerPageKopecks,
            'amount_kopecks' => $pages !== null ? $pages * $pricePerPageKopecks : null,
        ]);
    }

    /** Заказ создан в MySQL — запоминаем id и ссылку, чтобы повтор не создал второй */
    public function confirmed(string $platform, int $userId, int $documentId, ?string $paymentUrl): void
    {
        $this->put($platform, $userId, [
            'document_id' => $documentId,
            'payment_url' => $paymentUrl,
            'status' => 'awaiting_payment',
            'confirmed_at' => now()->toIso8601String(),
        ], self::TTL_PAID);
    }

    public function forget(string $platform, int $userId): void
    {
        Cache::forget($this->key($platform, $userId));
    }

    /** @param  array<string, mixed>  $values */
    private function put(string $platform, int $userId, array $values, ?int $ttl = null): void
    {
        $entry = $this->get($platform, $userId);

        if ($entry === null) {
            return;
        }

        Cache::put($this->key($platform, $userId), array_merge($entry, $values), $ttl ?? self::TTL_UNPAID);
    }

    private function key(string $platform, int $userId): string
    {
        return "file:{$platform}:{$userId}";
    }
}
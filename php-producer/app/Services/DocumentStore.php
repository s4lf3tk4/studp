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
            'pages_by_format' => null,
            'prices' => null,
            'print_mode' => null,
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

        /** Сколько страниц какого формата: цена зависит от формата бумаги */
    public function pages(string $platform, int $userId, array $formats): void
    {
        $this->put($platform, $userId, [
            'pages' => array_sum($formats),
            'pages_by_format' => $formats,     // формат => страниц
            'print_mode' => null,
            'prices' => null,
            'amount_kopecks' => null,
            'status' => 'counted',
            'counted_at' => now()->toIso8601String(),
        ]);
    }

    /** Режим выбран: цены за страницу по форматам и итог */
    public function mode(
        string $platform,
        int $userId,
        string $mode,
        array $prices,
        int $amountKopecks,
    ): void {
        $this->put($platform, $userId, [
            'print_mode' => $mode,
            'prices' => $prices,               // формат => копеек за страницу
            'amount_kopecks' => $amountKopecks,
        ]);
    }

    private function key(string $platform, int $userId): string
    {
        return "file:{$platform}:{$userId}";
    }
}
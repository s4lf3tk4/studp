<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Состояние «жду номера страниц» после нажатия «Цветные страницы»/«ЧБ страницы».
 * Живёт 10 минут: если клиент не ответил, состояние истекает и он возвращается к обычному флоу.
 */
final class PageSelection
{
    private const TTL = 600;

    /** @param  string  $baseMode  bw|color — режим всех страниц по умолчанию */
    public function start(string $platform, int $userId, string $baseMode, string $exceptionMode): void
    {
        $this->redis()->set(
            $this->key($platform, $userId),
            json_encode(['base_mode' => $baseMode, 'exception_mode' => $exceptionMode], JSON_UNESCAPED_UNICODE),
            'EX',
            self::TTL,
        );
    }

    /** @return array{base_mode: string, exception_mode: string}|null */
    public function get(string $platform, int $userId): ?array
    {
        $raw = $this->redis()->get($this->key($platform, $userId));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['base_mode'], $decoded['exception_mode'])) {
            return null;
        }

        return [
            'base_mode' => (string) $decoded['base_mode'],
            'exception_mode' => (string) $decoded['exception_mode'],
        ];
    }

    public function forget(string $platform, int $userId): void
    {
        $this->redis()->del($this->key($platform, $userId));
    }

    private function redis(): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection('cache');
    }

    private function key(string $platform, int $userId): string
    {
        return "pages:{$platform}:{$userId}";
    }
}
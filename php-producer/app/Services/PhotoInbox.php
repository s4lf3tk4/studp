<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Сбор фото из нескольких сообщений ВК в один заказ.
 * Список в Redis: добавление пачки — один атомарный RPUSH,
 * элементы сортируются по дате сообщения и номеру вложения.
 */
final class PhotoInbox
{
    /** Час на сбор: пользователь обычно присылает остальное сразу */
    private const TTL = 3600;

    public function isCollecting(string $platform, int $userId): bool
    {
        return (bool) $this->redis()->exists($this->flagKey($platform, $userId));
    }

    /**
     * Добавить пачку фото одной командой: либо все элементы, либо ни одного.
     *
     * @param  array<int, string>  $paths
     */
    public function push(string $platform, int $userId, array $paths, int $date): void
    {
        if ($paths === []) {
            return;
        }

        $items = [];

        foreach (array_values($paths) as $index => $path) {
            $items[] = $date.':'.$index.':'.$path;
        }

        $redis = $this->redis();
        $listKey = $this->listKey($platform, $userId);

        $redis->rpush($listKey, ...$items);
        $redis->expire($listKey, self::TTL);
        $redis->set($this->flagKey($platform, $userId), '1', 'EX', self::TTL);
    }

    /** @return array<int, string> пути в порядке прикрепления */
    public function items(string $platform, int $userId): array
    {
        $raw = $this->redis()->lrange($this->listKey($platform, $userId), 0, -1);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $items = [];

        foreach ($raw as $entry) {
            $parts = explode(':', (string) $entry, 3);

            if (count($parts) !== 3) {
                continue;
            }

            $items[] = [(int) $parts[0], (int) $parts[1], $parts[2]];
        }

        usort($items, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $item): string => $item[2], $items);
    }

    public function count(string $platform, int $userId): int
    {
        return (int) $this->redis()->llen($this->listKey($platform, $userId));
    }

    /** Сбор закончен или отменён: чистим и список, и флаг */
    public function forget(string $platform, int $userId): void
    {
        $redis = $this->redis();
        $redis->del($this->listKey($platform, $userId));
        $redis->del($this->flagKey($platform, $userId));
    }

    private function redis(): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection('cache');
    }

    private function listKey(string $platform, int $userId): string
    {
        return "photos:{$platform}:{$userId}";
    }

    private function flagKey(string $platform, int $userId): string
    {
        return "collecting:{$platform}:{$userId}";
    }
}
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Какой корпус выбрал пользователь — помним до отправки файла */
final class CampusChoice
{
    private const TTL = 86400;

    public function remember(string $platform, int $userId, string $campus): void
    {
        Cache::put($this->key($platform, $userId), $campus, self::TTL);
    }

    public function get(string $platform, int $userId): ?string
    {
        $campus = Cache::get($this->key($platform, $userId));

        return is_string($campus) ? $campus : null;
    }

    private function key(string $platform, int $userId): string
    {
        return "campus:{$platform}:{$userId}";
    }
}
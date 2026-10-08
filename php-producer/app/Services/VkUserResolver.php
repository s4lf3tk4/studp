<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Превращает from_id из события VK в человекочитаемое имя.
 *
 * Telegram присылает имя прямо в апдейте, а VK — только числовой from_id,
 * поэтому имя приходится запрашивать у API. Результат кэшируется, чтобы
 * не дёргать VK на каждое сообщение.
 */
final class VkUserResolver
{
    private const CACHE_TTL_OK = 86400;   // имя найдено — сутки

    private const CACHE_TTL_FAIL = 600;   // не нашли — 10 минут, чтобы не долбить API

    public function name(int $vkUserId): ?string
    {
        $token = (string) config('services.vk.access_token');

        if ($token === '') {
            return null;
        }

        $cacheKey = "vk:name:{$vkUserId}";
        $cached = Cache::get($cacheKey);

        // '' в кэше означает «спрашивали, имени нет»
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $name = $this->fetch($vkUserId, $token);

        Cache::put($cacheKey, $name ?? '', $name === null ? self::CACHE_TTL_FAIL : self::CACHE_TTL_OK);

        return $name;
    }

    private function fetch(int $vkUserId, string $token): ?string
    {
        // У сообщений из беседы/от имени сообщества from_id отрицательный
        $isGroup = $vkUserId < 0;
        $method = $isGroup ? 'groups.getById' : 'users.get';

        $response = Http::timeout(5)->get("https://api.vk.com/method/{$method}", [
            $isGroup ? 'group_ids' : 'user_ids' => abs($vkUserId),
            'access_token' => $token,
            'v' => config('services.vk.api_version'),
        ]);

        if ($response->failed()) {
            Log::warning('VK API недоступен', ['method' => $method, 'status' => $response->status()]);

            return null;
        }

        $payload = $response->json();

        // VK отдаёт ошибки с HTTP 200 и полем error
        if (isset($payload['error'])) {
            Log::warning('VK API вернул ошибку', ['method' => $method, 'error' => $payload['error']]);

            return null;
        }

        return $isGroup ? $this->groupName($payload) : $this->userName($payload);
    }

    private function userName(array $payload): ?string
    {
        $user = $payload['response'][0] ?? null;

        if (! is_array($user)) {
            return null;
        }

        $name = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function groupName(array $payload): ?string
    {
        // v5.199: {"response":{"groups":[{...}]}}, в старых версиях: {"response":[{...}]}
        $group = $payload['response']['groups'][0] ?? $payload['response'][0] ?? null;

        if (! is_array($group)) {
            return null;
        }

        $name = trim((string) ($group['name'] ?? ''));

        return $name !== '' ? $name : null;
    }
}

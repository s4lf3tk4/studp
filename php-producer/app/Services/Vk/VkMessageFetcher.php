<?php

namespace App\Services\Vk;

use App\Support\VkPhoto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Полное сообщение из VK API.
 * Callback присылает не больше одного вложения, а messages.getById отдаёт все.
 */
final class VkMessageFetcher
{
    /** @return array<int, string> ссылки на фото; пусто, если API недоступен */
    public function photoUrls(int $messageId, int $groupId): array
    {
        $token = (string) config('services.vk.access_token');
        $groupId = $groupId > 0 ? $groupId : (int) config('services.vk.group_id', 0);

        if ($messageId <= 0 || $groupId <= 0 || $token === '') {
            return [];
        }

        try {
            $response = Http::timeout(5)->get('https://api.vk.com/method/messages.getById', [
                'message_ids' => (string) $messageId,
                'group_id' => $groupId,
                'access_token' => $token,
                'v' => (string) config('services.vk.api_version', '5.199'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('VK API недоступен при запросе сообщения', ['error' => $e->getMessage()]);

            return [];
        }

        $payload = $response->json();

        // VK отдаёт ошибки с HTTP 200 и полем error
        if (is_array($payload) && isset($payload['error'])) {
            Log::warning('VK API вернул ошибку на messages.getById', ['error' => $payload['error']]);

            return [];
        }

        $message = is_array($payload) ? ($payload['response']['items'][0] ?? null) : null;

        if (! is_array($message)) {
            return [];
        }

        $urls = [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) !== 'photo') {
                continue;
            }

            $url = VkPhoto::largestUrl($attachment['photo'] ?? null);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
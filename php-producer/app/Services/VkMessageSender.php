<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщений в VK (messages.send).
 * Аналог SendResponse::vkSendMessage(), но токен берётся из config,
 * а запрос идёт через HTTP-клиент Laravel.
 */
final class VkMessageSender
{
    private const API_URL = 'https://api.vk.com/method/messages.send';

    /**
     * @param  array<string, mixed>|null  $keyboard
     */
    public function send(int $peerId, ?string $text, ?array $keyboard = null): bool
    {
        if ($text === null || $text === '') {
            return false; // нечего отправлять
        }

        $token = (string) config('services.vk.access_token');

        if ($token === '') {
            Log::warning('VK_ACCESS_TOKEN пуст — сообщение не отправлено');

            return false;
        }

        $params = [
            'access_token' => $token,
            'v' => config('services.vk.api_version'),
            'peer_id' => $peerId,
            'message' => $text,
            // random_id защищает от повторной отправки при наших ретраях
            'random_id' => random_int(1, PHP_INT_MAX),
        ];

        if ($keyboard !== null) {
            $params['keyboard'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->asForm()
                ->post(self::API_URL, $params);
        } catch (\Throwable $e) {
            Log::error('VK messages.send: '.$e->getMessage());

            return false;
        }

        if ($response->failed()) {
            Log::error('VK messages.send: HTTP '.$response->status());

            return false;
        }

        $decoded = $response->json();

        // VK отдаёт ошибки с HTTP 200 и полем error
        if (isset($decoded['error'])) {
            Log::error('VK messages.send error: '.json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));

            return false;
        }

        return true;
    }
}

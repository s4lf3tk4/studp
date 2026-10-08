<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщений в MAX: POST /messages?user_id=...
 * Документация: https://dev.max.ru/docs-api/methods/POST/messages
 */
final class MaxMessageSender
{
    public function send(int $userId, ?string $text): bool
    {
        if ($text === null || $text === '') {
            return false; // нечего отправлять
        }

        $token = (string) config('services.max.access_token');

        if ($token === '') {
            Log::warning('MAX_ACCESS_TOKEN пуст — сообщение не отправлено');

            return false;
        }

        $url = rtrim((string) config('services.max.base_url'), '/').'/messages?user_id='.$userId;

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['Authorization' => $token])
                ->post($url, ['text' => $text]);
        } catch (\Throwable $e) {
            Log::error('MAX /messages: '.$e->getMessage());

            return false;
        }

        if ($response->failed()) {
            Log::error('MAX /messages: HTTP '.$response->status().' '.$response->body());

            return false;
        }

        return true;
    }
}

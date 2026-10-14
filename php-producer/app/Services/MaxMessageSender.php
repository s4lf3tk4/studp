<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщений в MAX: POST /messages?user_id=... | ?chat_id=...
 * Документация: https://dev.max.ru/docs-api/methods/POST/messages
 */
final class MaxMessageSender
{
    /** Сообщение пользователю (диалог) */
    public function send(int $userId, ?string $text, ?array $attachments = null): bool
    {
        return $this->push('user_id='.$userId, $text, $attachments);
    }

    /**
     * Сообщение в чат. Нужно для событий без пользователя — например bot_started,
     * где известен только chat_id диалога.
     */
    public function sendToChat(int $chatId, ?string $text, ?array $attachments = null): bool
    {
        return $this->push('chat_id='.$chatId, $text, $attachments);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $attachments
     *         вложения MAX: картинки, файлы, inline_keyboard с кнопками
     */
    private function push(string $query, ?string $text, ?array $attachments = null): bool
    {
        // Кнопка может уходить без текста, поэтому проверяем оба поля
        if (($text === null || $text === '') && empty($attachments)) {
            return false;
        }

        $token = (string) config('services.max.access_token');

        if ($token === '') {
            Log::warning('MAX_ACCESS_TOKEN пуст — сообщение не отправлено');

            return false;
        }

        $url = rtrim((string) config('services.max.base_url'), '/').'/messages?'.$query;

        // null-поля не отправляем: пустой text в MAX допустим,
        // но лишний ключ в теле только мешает
        $body = array_filter([
            'text' => $text,
            'attachments' => $attachments,
        ], fn ($value) => $value !== null);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['Authorization' => $token])
                ->post($url, $body);
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
       
    /**
     * Подтверждение нажатия inline-кнопки: POST /answers?callback_id=...
     * Если передать текст — сообщение с кнопкой заменяется этим текстом.
     * Документация: https://dev.max.ru/docs-api/methods/POST/answers
     *
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    public function answer(string $callbackId, ?string $text = null, ?array $attachments = null): bool
    {
        $token = (string) config('services.max.access_token');

        if ($token === '') {
            Log::warning('MAX_ACCESS_TOKEN пуст — callback не подтверждён');

            return false;
        }

        $url = rtrim((string) config('services.max.base_url'), '/').'/answers?callback_id='.urlencode($callbackId);

        $body = [];

        if ($text !== null && $text !== '') {
            $body['message'] = array_filter([
                'text' => $text,
                'attachments' => $attachments,
            ], fn ($value) => $value !== null);
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['Authorization' => $token])
                // пустой объект, а не пустой массив: MAX ждёт объект тела
                ->post($url, $body === [] ? (object) [] : $body);
        } catch (\Throwable $e) {
            Log::error('MAX /answers: '.$e->getMessage());

            return false;
        }

        if ($response->failed()) {
            Log::error('MAX /answers: HTTP '.$response->status().' '.$response->body());

            return false;
        }

        // MAX отдаёт 200 и при неуспехе — смотрим поле success
        if ($response->json('success') !== true) {
            Log::warning('MAX /answers: '.$response->body());

            return false;
        }

        return true;
    }
}
<?php

namespace App\Services;

use App\Jobs\SendVkReply;
use App\Jobs\SendMaxReply;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class WebhookLogics
{
    public function __construct(private readonly VkEventStore $events) {}

    public function handle(array $payload, ?string $source): Response
    {
        return match ($source) {
            'vk' => $this->handleVk($payload),
            'max' => $this->handleMax($payload),
            default => response()->json(['ok' => false, 'error' => 'unknown_source']),
        };
    }

    private function handleVk(array $payload): Response
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === 'confirmation') {
            return $this->confirmVk();
        }

        $eventId = (string) ($payload['event_id'] ?? '');

        // Занимаем event_id до обработки: повторная доставка того же события
        // не должна приводить к повторному ответу в VK
        if ($eventId !== '' && ! $this->events->claim($eventId, $payload)) {
            Log::info('VK: повторная доставка события, пропускаем', [
                'event_id' => $eventId,
                'type' => $type,
            ]);

            return response('ok');
        }

        $message = $payload['object']['message'] ?? [];
        $text = is_string($message['text'] ?? null) ? $message['text'] : null;
        $peerId = is_int($message['peer_id'] ?? null) ? $message['peer_id'] : null;
        $userId = is_int($message['from_id'] ?? null) ? $message['from_id'] : null;

        // Документ во вложении: VK кладёт его в message.attachments[type=doc]
        $doc = null;

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) === 'doc' && is_array($attachment['doc'] ?? null)) {
                $doc = $attachment['doc'];
                break;
            }
        }

        Log::info('Incoming message', [
            'source' => 'vk',
            'type' => $type,
            'event_id' => $eventId,
            'user_id' => $userId,
            'peer_id' => $peerId,
            'text' => $text,
            'attachments' => $message['attachments'] ?? [],   // видно, что именно прислали
        ]);

        // Файл: скачиваем и считаем страницы
        if ($doc !== null && $peerId !== null) {
            defer(function () use ($doc, $peerId) {
                $client = app(VkMessageSender::class);

                try {
                    $url = $doc['url'] ?? null;

                    if (! is_string($url) || $url === '') {
                        $client->send($peerId, 'Нет ссылки на документ: '.json_encode($doc, JSON_UNESCAPED_UNICODE));

                        return;
                    }

                    // Ссылка на документ VK временная (~час) и не требует токена
                    $response = Http::timeout(120)->connectTimeout(10)->get($url);

                    if ($response->failed()) {
                        $client->send($peerId, 'Скачать документ не удалось: HTTP '.$response->status());

                        return;
                    }

                    $extension = strtolower((string) ($doc['ext'] ?? ''));

                    if (! in_array($extension, ['pdf', 'docx'], true)) {
                        $extension = 'bin';
                    }

                    $path = 'tmp/'.md5((string) microtime(true)).'.'.$extension;

                    Storage::disk('local')->put($path, $response->body());

                    try {
                        $pages = app(DocumentPageCounter::class)->count(Storage::disk('local')->path($path));
                    } finally {
                        Storage::disk('local')->delete($path);
                    }

                    $client->send($peerId, sprintf('В файле %d стр.', $pages));
                } catch (\Throwable $e) {
                    Log::error('Подсчёт страниц (VK) не удался', ['error' => $e->getMessage()]);

                    $client->send($peerId, 'Ошибка подсчёта: '.$e->getMessage());
                }
            });

            // VK считает событие доставленным только при ответе "ok" и HTTP 200
            return response('ok');
        }

        if ($text !== null && $text !== '' && $peerId !== null) {
            // Ответ уходит после того, как VK получит "ok",
            // иначе его запрос будет ждать наш поход в messages.send
            SendVkReply::dispatchAfterResponse($peerId, $text, $userId);
        }

        // VK считает событие доставленным только при ответе "ok" и HTTP 200
        return response('ok');
    }

    /** VK ожидает в ответ ровно строку с кодом, без JSON */
    private function confirmVk(): Response
    {
        $code = (string) config('services.vk.confirmation_code');

        if ($code === '') {
            Log::warning('VK прислал confirmation, но VK_CONFIRMATION_CODE пуст — адрес не подтвердится');
        }

        return response($code);
    }

    private function handleMax(array $payload): Response
    {
        $type = (string) ($payload['update_type'] ?? '');

        $message = $payload['message'] ?? [];
        $body = $message['body'] ?? [];
        $text = is_string($body['text'] ?? null) ? $body['text'] : null;

        // Дедупликация: MAX повторяет доставку до 10 раз, ключ — id сообщения
        $mid = $body['mid'] ?? null;
        $eventKey = 'max:'.(is_string($mid) || is_int($mid) ? $mid : $type.'@'.($payload['timestamp'] ?? ''));

        if (! $this->events->claim($eventKey, $payload)) {
            Log::info('MAX: повторная доставка события, пропускаем', ['key' => $eventKey]);

            return response('ok');
        }

        $sender = $message['sender'] ?? [];
        $userId = is_int($sender['user_id'] ?? null) ? $sender['user_id'] : null;

        Log::info('Incoming message', [
            'source' => 'max',
            'update_type' => $type,
            'user_id' => $userId,
            'name' => $sender['name'] ?? null,
            'text' => $text,
            'raw' => $payload, // временно: пока формат события не подтверждён живыми данными
        ]);
        $fileAttachment = null;

        foreach ($body['attachments'] ?? [] as $attachment) {
            if (($attachment['type'] ?? null) === 'file') {
                $fileAttachment = $attachment;
                break;
            }
        }

        // Файл: скачиваем, считаем страницы, отвечаем числом
        if ($fileAttachment !== null && $userId !== null) {
            defer(function () use ($fileAttachment, $userId) {
                $client = app(MaxMessageSender::class);

                try {
                    $fileMeta = $fileAttachment['payload'] ?? [];
                    $url = $fileMeta['url'] ?? null;

                    // Если ссылки нет — покажем вложение в чат, чтобы увидеть фактические поля
                    if (! is_string($url) || $url === '') {
                        $client->send($userId, 'Нет ссылки на файл. Вложение: '.json_encode($fileAttachment, JSON_UNESCAPED_UNICODE));

                        return;
                    }

                    $response = Http::withHeaders([
                        'Authorization' => (string) config('services.max.access_token'),
                    ])->timeout(120)->connectTimeout(10)->get($url);

                    if ($response->failed()) {
                        $client->send($userId, 'Скачать файл не удалось: HTTP '.$response->status());

                        return;
                    }

                    // MAX присылает имя файла в attachment.filename, а payload.name нет
                    $name = (string) ($fileAttachment['filename'] ?? $fileMeta['name'] ?? '');
                    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                    if (! in_array($extension, ['pdf', 'docx'], true)) {
                        $contentType = (string) $response->header('Content-Type');
                        $content = $response->body();

                        // Запасные варианты: Content-Type и сигнатура файла
                        $extension = match (true) {
                            str_contains($contentType, 'pdf') => 'pdf',
                            str_contains($contentType, 'word') => 'docx',
                            str_starts_with($content, '%PDF') => 'pdf',
                            str_starts_with($content, "PK\x03\x04") => 'docx',
                            default => 'bin',
                        };
                    }

                    $path = 'tmp/'.md5((string) microtime(true)).'.'.$extension;

                    Storage::disk('local')->put($path, $response->body());

                    try {
                        $pages = app(DocumentPageCounter::class)->count(Storage::disk('local')->path($path));
                    } finally {
                        Storage::disk('local')->delete($path);
                    }

                    $client->send($userId, sprintf('В файле %d стр.', $pages));
                } catch (\Throwable $e) {
                    Log::error('Подсчёт страниц не удался', ['error' => $e->getMessage()]);

                    $client->send($userId, 'Ошибка подсчёта: '.$e->getMessage());
                }
            });

            return response('ok');
        }

        if ($text !== null && $text !== '' && $userId !== null) {
            // Ответ уходит после того, как MAX получит 200
            SendMaxReply::dispatchAfterResponse($userId, $text, $sender['name'] ?? null);
        }

        // MAX считает событие доставленным только при HTTP 200
        return response('ok');
    }
}

<?php

namespace App\Services\Webhook\Max;

use App\Jobs\ProcessIncomingDocument;
use App\Jobs\SendMaxReply;
use App\Services\EventStore;
use App\Services\MaxMessageSender;
use App\Services\Webhook\StartCommand;
use App\Services\Webhook\WebhookHandler;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class MaxWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly EventStore $events) {}

    public function source(): string
    {
        return 'max';
    }

    public function handle(array $payload): Response
    {
        $event = MaxEvent::fromPayload($payload);

        if (! $this->events->claim($event->eventKey, $payload)) {
            Log::info('MAX: повторная доставка события, пропускаем', ['key' => $event->eventKey]);

            return response('ok');
        }

        Log::info('Incoming message', [
            'source' => 'max',
            'update_type' => $event->type,
            'user_id' => $event->userId,
            'name' => $event->senderName,
            'text' => $event->text,
            'raw' => $event->raw, // временно: пока формат bot_started не подтверждён живыми данными
        ]);

        // Кнопка «Начать» приходит отдельным событием
        if ($event->type === 'bot_started') {
            return $this->handleBotStarted($event);
        }

        if (StartCommand::matches($event->raw['message'] ?? [], $event->text)) {
            return $this->replyTo($event, BotTexts::welcome());
        }

        if ($event->file !== null) {
            return $this->handleFile($event);
        }

        if ($event->text !== null && $event->text !== '' && $event->userId !== null) {
            SendMaxReply::dispatchAfterResponse($event->userId, $event->text, $event->senderName);
        }

        // MAX считает событие доставленным только при HTTP 200
        return response('ok');
    }

    private function handleBotStarted(MaxEvent $event): Response
    {
        if ($event->userId !== null) {
            return $this->replyTo($event, BotTexts::welcome());
        }

        if ($event->chatId !== null) {
            defer(fn () => app(MaxMessageSender::class)->sendToChat($event->chatId, BotTexts::welcome()));
        }

        return response('ok');
    }

    private function handleFile(MaxEvent $event): Response
    {
        $file = $event->file;
        $url = (string) ($file['payload']['url'] ?? '');
        $fileName = (string) ($file['filename'] ?? '');
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            return $this->replyTo($event, BotTexts::onlyPdf());
        }

        if ($url === '' || $event->userId === null) {
            Log::warning('MAX: во вложении нет payload.url', ['attachment' => $file]);

            return $this->replyTo($event, BotTexts::noFileLink());
        }

        ProcessIncomingDocument::dispatch(
            platform: 'max',
            recipientId: $event->userId,
            fileUrl: $url,
            fileName: $fileName,
            extension: $extension,
            size: isset($file['size']) ? (int) $file['size'] : null,
        );

        return response('ok');
    }

    private function replyTo(MaxEvent $event, string $text): Response
    {
        if ($event->userId === null) {
            return response('ok');
        }

        defer(fn () => app(MaxMessageSender::class)->send($event->userId, $text));

        return response('ok');
    }
}
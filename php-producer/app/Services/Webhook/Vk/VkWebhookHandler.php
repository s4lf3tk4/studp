<?php

namespace App\Services\Webhook\Vk;

use App\Jobs\ProcessIncomingDocument;
use App\Jobs\SendVkReply;
use App\Services\EventStore;
use App\Services\VkMessageSender;
use App\Services\Webhook\BotCommand;
use App\Services\Webhook\WebhookHandler;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VkWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly EventStore $events) {}

    public function source(): string
    {
        return 'vk';
    }

    public function handle(array $payload): Response
    {
        $event = VkEvent::fromPayload($payload);

        // Подтверждение адреса Callback API: ровно код, без JSON
        if ($event->type === 'confirmation') {
            return $this->confirm();
        }

        // Повторная доставка не должна приводить к повторному ответу
        if ($event->eventId !== '' && ! $this->events->claim($event->eventId, $payload)) {
            Log::info('VK: повторная доставка события: ', ['event_id' => $event->eventId]);

            return response('ok');
        }

        Log::info('Incoming message', [
            'source' => 'vk',
            'type' => $event->type,
            'event_id' => $event->eventId,
            'user_id' => $event->userId,
            'peer_id' => $event->peerId,
            'text' => $event->text,
            'attachments' => $event->message['attachments'] ?? [],
        ]);

        // Кнопка Начать, кнопка Отправить файл или /start
        $command = BotCommand::detect($event->message, $event->text);

        if ($event->peerId !== null && $command !== null) {
            return $this->handleCommand($event->peerId, $command);
        }

        if ($event->doc !== null && $event->peerId !== null) {
            return $this->handleDocument($event);
        }

        if ($event->text !== null && $event->text !== '' && $event->peerId !== null) {
            SendVkReply::dispatchAfterResponse($event->peerId, $event->text, $event->userId);
        }

        // VK считает событие доставленным только при ответе "ok" и HTTP 200
        return response('ok');
    }

    private function handleCommand(VkEvent $event, string $command): Response
    {
        $peerId = $event->peerId;                    // куда отвечать
        $userId = $event->userId ?? $peerId;         // кто отправил: в личке совпадают, в беседе нет

        if (BotCommand::isCampus($command)) {
            app(CampusChoice::class)->remember('vk', $userId, $command);

            return $this->reply(
                $peerId,
                BotTexts::campusChosen(BotCommand::CAMPUSES[$command]),
                VkKeyboard::sendFile(),
            );
        }

        [$text, $keyboard] = match ($command) {
            BotCommand::START => [BotTexts::welcome(), VkKeyboard::chosenCampus()],
            BotCommand::SEND_FILE => [BotTexts::fileInstructions(), null],
            default => [null, null],
        };

        if ($text === null) {
            return response('ok');
        }

        return $this->reply($peerId, $text, $keyboard);
    }

    private function handleDocument(VkEvent $event): Response
    {
        $url = (string) ($event->doc['url'] ?? '');
        $extension = strtolower((string) ($event->doc['ext'] ?? ''));

        if ($extension !== 'pdf') {
            return $this->reply($event->peerId, BotTexts::onlyPdf());
        }

        if ($url === '') {
            Log::warning('VK: в документе нет url', ['doc' => $event->doc]);

            return $this->reply($event->peerId, BotTexts::noFileLink());
        }

        $userId = $event->userId ?? $event->peerId;
        $campus = app(CampusChoice::class)->get('vk', $userId);

        if ($campus === null) {
            return $this->reply($event->peerId, BotTexts::campusRequired(), VkKeyboard::chosenCampus());
        }

        ProcessIncomingDocument::dispatch(
            platform: 'vk',
            recipientId: $event->peerId,   // куда отвечать
            userId: $userId,               // кто отправил — это и есть «id пользователя»
            fileUrl: $url,
            fileName: $event->doc['title'] ?? null,
            extension: $extension,
            size: isset($event->doc['size']) ? (int) $event->doc['size'] : null,
            campus: $campus,
        );

        return response('ok');
    }

    private function reply(int $peerId, string $text, ?array $keyboard = null): Response
    {
        defer(fn () => app(VkMessageSender::class)->send($peerId, $text, $keyboard));

        return response('ok');
    }

    private function confirm(): Response
    {
        $code = (string) config('services.vk.confirmation_code');

        if ($code === '') {
            Log::warning('VK прислал confirmation, но VK_CONFIRMATION_CODE пуст — адрес не подтвердится');
        }

        return response($code);
    }
}
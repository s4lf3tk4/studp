<?php

namespace App\Services\Webhook\Vk;

use App\Jobs\ProcessIncomingDocument;
use App\Jobs\SendBotReply;
use App\Models\Campus;
use App\Models\ChatState;
use App\Models\Document;
use App\Services\CampusChoice;
use App\Services\DocumentStore;
use App\Services\EventStore;
use App\Services\OrderCancellation;
use App\Services\OrderConfirmation;
use App\Services\PrintModeChoice;
use App\Services\VkUserResolver;
use App\Services\Webhook\BotCommand;
use App\Services\Webhook\WebhookHandler;
use App\Support\BotTexts;
use App\Support\ScheduleText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Jobs\CollectIncomingPhotos;
use App\Jobs\FinalizePhotoOrder;
use App\Services\PhotoInbox;

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
                // Временная диагностика фото: пишем сырое событие в отдельный файл
        if (($payload['type'] ?? '') === 'message_new') {
            $attachments = $payload['object']['message']['attachments'] ?? [];

            if ($attachments !== []) {
                @file_put_contents(
                    storage_path('logs/vk-attachments.log'),
                    json_encode([
                        'event_id' => $payload['event_id'] ?? null,
                        'message_id' => $payload['object']['message']['id'] ?? null,
                        'conversation_message_id' => $payload['object']['message']['conversation_message_id'] ?? null,
                        'date' => $payload['object']['message']['date'] ?? null,
                        'count' => count($attachments),
                        'types' => array_map(fn (array $a): string => (string) ($a['type'] ?? '?'), $attachments),
                    ], JSON_UNESCAPED_UNICODE).PHP_EOL,
                    FILE_APPEND,
                );
            }
        }

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

        // «Начать», «Отправить файл», «Корпус N», «ЧБ», «Цветной», «Оператор», «К боту»
        $command = BotCommand::detect($event->message, $event->text);

        if ($event->peerId === null) {
            return response('ok');
        }

        // Кто отправил: в личке совпадает с peer_id, в беседе — нет
        $userId = $event->userId ?? $event->peerId;

        // Переключение режима работает всегда, в любом состоянии
        if ($command === BotCommand::CHAT_OPERATOR) {
            return $this->switchToOperator($event->peerId, $userId);
        }

        if ($command === BotCommand::CHAT_BOT) {
            return $this->switchToBot($event->peerId, $userId);
        }

        // Режим оператора: переписка идёт в интерфейсе сообщества ВК, бот не отвечает
        if (ChatState::isOperator('vk', $userId)) {
            return response('ok');
        }

        // Фото: собираем в один заказ, даже если пришли несколькими сообщениями
        if ($event->photos !== []) {
            return $this->handlePhotos($event, $userId);
        }

        if ($command !== null) {
            return $this->handleCommand($event, $command);
        }

        if ($event->doc !== null) {
            return $this->handleDocument($event);
        }

        if ($event->text !== null && $event->text !== '') {
            return $this->reply($event->peerId, BotTexts::unknownCommand(), VkKeyboard::chosenCampus());
        }

        // VK считает событие доставленным только при ответе "ok" и HTTP 200
        return response('ok');
    }

    /** Переход в режим оператора: запоминаем имя, уведомляем оператора, даём кнопку возврата */
    private function switchToOperator(int $peerId, int $userId): Response
    {
        $name = app(VkUserResolver::class)->name($userId);

        ChatState::setMode('vk', $userId, ChatState::MODE_OPERATOR, $name);

        $this->notifyOperator($userId, $name);

        return $this->reply($peerId, BotTexts::operatorWelcome(), VkKeyboard::backToBot());
    }

    /** Оператору на его личный id уходит сообщение от лица сообщества */
    private function notifyOperator(int $userId, ?string $name): void
    {
        $operatorId = (int) config('services.vk.operator_id', 0);

        if ($operatorId <= 0) {
            Log::warning('Переход в режим оператора, но VK_OPERATOR_ID не задан', ['user_id' => $userId]);

            return;
        }

        dispatch(SendBotReply::vk($operatorId, BotTexts::operatorRequested($name, $userId)));
    }

    /** Возврат к боту: обычное меню с корпусами */
    private function switchToBot(int $peerId, int $userId): Response
    {
        ChatState::setMode('vk', $userId, ChatState::MODE_BOT);

        return $this->reply($peerId, BotTexts::backToBot(), VkKeyboard::chosenCampus());
    }

    private function handleCommand(VkEvent $event, string $command): Response
    {
        $peerId = $event->peerId;                    // куда отвечать
        $userId = $event->userId ?? $peerId;         // кто отправил: в личке совпадают, в беседе нет

        // Решение по собранным фото
        if ($command === BotCommand::PHOTOS_DONE) {
            FinalizePhotoOrder::dispatch('vk', $userId);

            return response('ok');
        }

        if ($command === BotCommand::PHOTOS_CANCEL) {
            return $this->cancelPhotos($peerId, $userId);
        }

        // Подтверждение заказа: заказ в MySQL и ссылка на оплату
        if ($command === BotCommand::CONFIRM) {
            $result = app(OrderConfirmation::class)->confirm('vk', $userId);

            // Банк не ответил — возвращаем кнопки, чтобы клиент повторил или отменил
            $keyboard = $result->paymentFailed
                ? VkKeyboard::confirmOrCancel()
                : VkKeyboard::chosenCampus();

            return $this->reply($peerId, $result->text, $keyboard);
        }

        if ($command === BotCommand::CANCEL) {
            return $this->reply(
                $peerId,
                app(OrderCancellation::class)->cancel('vk', $userId),
                VkKeyboard::chosenCampus(),
            );
        }

        // Принтер: цена ЧБ и цветного разная, поэтому спрашиваем до выставления счёта
        if (BotCommand::isMode($command)) {
            $result = app(PrintModeChoice::class)->choose('vk', $userId, (string) BotCommand::mode($command));

            $keyboard = match ($result['stage']) {
                PrintModeChoice::STAGE_CONFIRM => VkKeyboard::confirmOrCancel(),
                PrintModeChoice::STAGE_MODE => VkKeyboard::printMode(),
                default => VkKeyboard::chosenCampus(),
            };

            return $this->reply($peerId, $result['text'], $keyboard);
        }

        // Кнопка корпуса: код пришёл из клавиатуры, остальное — из БД
        if (BotCommand::isCampus($command)) {
            $campus = Campus::query()->where('code', $command)->first();

            if ($campus === null) {
                return $this->reply($peerId, BotTexts::unknownCommand(), VkKeyboard::chosenCampus());
            }

            // Красная кнопка: корпус выключен — не запоминаем и не считаем
            if (! $campus->is_active) {
                return $this->reply(
                    $peerId,
                    BotTexts::campusInactive((string) $campus->title),
                    VkKeyboard::chosenCampus(),
                );
            }

            app(CampusChoice::class)->remember('vk', $userId, $command);

            return $this->reply(
                $peerId,
                BotTexts::campusChosen(
                    (string) $campus->title,
                    ScheduleText::forCampus($command),
                    Document::queuedForCampus($command),
                ),
                VkKeyboard::cancelBtn()
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

    /** Пачка фото: качаем и складываем в сбор на очереди photos */
    private function handlePhotos(VkEvent $event, int $userId): Response
    {
        Log::info('Фото в сообщении', [
            'user_id' => $userId,
            'attachments' => count($event->message['attachments'] ?? []),
            'photos' => count($event->photos),
            'types' => array_map(
                fn (array $item): string => (string) ($item['type'] ?? '?'),
                $event->message['attachments'] ?? [],
            ),
        ]);
        $campus = app(CampusChoice::class)->get('vk', $userId);

        if ($campus === null) {
            return $this->reply($event->peerId, BotTexts::campusRequired(), VkKeyboard::chosenCampus());
        }

        CollectIncomingPhotos::dispatch(
            platform: 'vk',
            recipientId: $event->peerId,
            userId: $userId,
            urls: $event->photos,
            date: $event->date,
            campus: $campus,
            messageId: $event->messageId,
            groupId: $event->groupId,
        );

        // Ответит джоба, когда файлы уже на диске: «Принял N фото (всего M)»
        return response('ok');
    }

    /** «Отмена»: чистим собранные файлы и состояние сбора */
    private function cancelPhotos(int $peerId, int $userId): Response
    {
        $inbox = app(PhotoInbox::class);
        $disk = \Illuminate\Support\Facades\Storage::disk('documents');

        foreach ($inbox->items('vk', $userId) as $path) {
            $disk->delete($path);
        }

        $inbox->forget('vk', $userId);
        app(DocumentStore::class)->forget('vk', $userId);

        return $this->reply($peerId, BotTexts::photosCanceled(), VkKeyboard::chosenCampus());
    }

    private function handleDocument(VkEvent $event): Response
    {
        $url = (string) ($event->doc['url'] ?? '');
        $extension = strtolower((string) ($event->doc['ext'] ?? ''));

        $allowed = ['pdf', 'docx'];

        if (! in_array($extension, $allowed, true)) {
            return $this->reply($event->peerId, BotTexts::wrongFormat());
        }

        if ($url === '') {
            Log::warning('VK: в документе нет url', ['doc' => $event->doc]);

            return $this->reply($event->peerId, BotTexts::noFileLink());
        }

        $maxBytes = (int) config('services.vk.max_file_bytes', 30 * 1024 * 1024);

        if (isset($event->doc['size']) && (int) $event->doc['size'] > $maxBytes) {
            Log::info('VK: файл больше лимита', [
                'user_id' => $event->userId,
                'size' => (int) $event->doc['size'],
            ]);

            return $this->reply($event->peerId, BotTexts::tooBig(intdiv($maxBytes, 1048576)));
        }

        $userId = $event->userId ?? $event->peerId;
        $campus = app(CampusChoice::class)->get('vk', $userId);

        if ($campus === null) {
            return $this->reply($event->peerId, BotTexts::campusRequired(), VkKeyboard::chosenCampus());
        }

        app(DocumentStore::class)->remember(
            platform: 'vk',
            userId: $userId,
            recipientId: $event->peerId,
            doc: $event->doc,
            campus: $campus,
        );

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

        return $this->reply($event->peerId, BotTexts::counting());
    }

    private function reply(int $peerId, string $text, ?array $keyboard = null): Response
    {
        dispatch(SendBotReply::vk($peerId, $text, $keyboard));

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
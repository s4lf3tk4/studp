<?php

namespace App\Services\Webhook\Max;

use App\Jobs\ProcessIncomingDocument;
use App\Jobs\SendMaxReply;
use App\Models\Campus;
use App\Services\CampusChoice;
use App\Services\DocumentStore;
use App\Services\EventStore;
use App\Services\MaxMessageSender;
use App\Services\OrderConfirmation;
use App\Services\OrderCancellation;
use App\Services\Webhook\BotCommand;
use App\Services\Webhook\WebhookHandler;
use App\Support\BotTexts;
use App\Support\ScheduleText;
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
            'callback' => $event->callbackId,
            'command' => $event->command,
        ]);

        // Первое открытие бота: показываем, что умеем, и кнопки корпусов
        if ($event->type === 'bot_started') {
            return $this->handleCommand($event, BotCommand::START);
        }

        // Нажатие кнопки (message_callback) или текст: /start, «Отправить файл», «Корпус 1»
        $command = $event->command ?? BotCommand::detect($event->raw['message'] ?? [], $event->text);

        if ($command !== null) {
            return $this->handleCommand($event, $command);
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

    private function handleCommand(MaxEvent $event, string $command): Response
    {
        // Подтверждение заказа: черновик из Redis превращается в запись в MySQL
        if ($command === BotCommand::CONFIRM) {
            $userId = $event->userId;

            if ($userId === null) {
                return response('ok');
            }

            return $this->reply(
                $event,
                app(OrderConfirmation::class)->confirm('max', $userId),
                MaxKeyboard::chosenCampus(),
            );
        }
        if ($command === BotCommand::CANCEL) {
            $userId = $event->userId;

            if ($userId === null) {
                return response('ok');
            }

            return $this->reply(
                $event,
                app(OrderCancellation::class)->cancel('max', $userId),
                MaxKeyboard::chosenCampus(),
            );
        }

        if (BotCommand::isCampus($command)) {
            $userId = $event->userId;

            if ($userId === null) {
                return response('ok');
            }

            // Корпус выключен: кнопка помечена «не работает», выбор запрещён
            if (! Campus::isActive($command)) {
                return $this->reply(
                    $event,
                    BotTexts::campusInactive(BotCommand::CAMPUSES[$command]),
                    MaxKeyboard::chosenCampus(),
                );
            }

            app(CampusChoice::class)->remember('max', $userId, $command);

            return $this->reply(
                $event,
                BotTexts::campusChosen(BotCommand::CAMPUSES[$command], ScheduleText::forCampus($command)),
                MaxKeyboard::cancelBtn(),
            );
        }

        [$text, $keyboard] = match ($command) {
            BotCommand::START => [BotTexts::welcome(), MaxKeyboard::chosenCampus()],
            BotCommand::SEND_FILE => [BotTexts::fileInstructions(), null],
            BotCommand::CANCEL => [BotTexts::cancelBtn(), MaxKeyboard::chosenCampus()],
            default => [null, null],
        };

        if ($text === null) {
            return response('ok');
        }

        return $this->reply($event, $text, $keyboard);
    }

    private function handleFile(MaxEvent $event): Response
    {
        $file = $event->file;
        $url = (string) ($file['payload']['url'] ?? '');
        $fileName = (string) ($file['filename'] ?? '');
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            return $this->reply($event, BotTexts::onlyPdf());
        }

        if ($url === '' || $event->userId === null) {
            Log::warning('MAX: во вложении нет payload.url', ['attachment' => $file]);

            return $this->reply($event, BotTexts::noFileLink());
        }

        $campus = app(CampusChoice::class)->get('max', $event->userId);

        if ($campus === null) {
            return $this->reply($event, BotTexts::campusRequired(), MaxKeyboard::chosenCampus());
        }

        app(DocumentStore::class)->remember(
            platform: 'max',
            userId: $event->userId,
            recipientId: $event->userId,
            doc: [
                'url' => $url,
                'title' => $fileName,
                'ext' => $extension,
                'size' => $file['size'] ?? null,
            ],
            campus: $campus,
        );

        ProcessIncomingDocument::dispatch(
            platform: 'max',
            recipientId: $event->userId,
            userId: $event->userId,
            fileUrl: $url,
            fileName: $fileName,
            extension: $extension,
            size: isset($file['size']) ? (int) $file['size'] : null,
            campus: $campus,
        );

        return $this->reply($event, BotTexts::counting());
    }

    /** Кнопку подтверждаем ответом на callback и заменяем то же сообщение */
    private function reply(MaxEvent $event, string $text, ?array $keyboard = null): Response
    {
        $attachments = $keyboard === null ? null : [$keyboard];
        $callbackId = $event->callbackId;
        $userId = $event->userId;
        $chatId = $event->chatId;

        if ($callbackId !== null) {
            defer(function () use ($callbackId, $text, $attachments, $userId, $chatId): void {
                $sender = app(MaxMessageSender::class);

                // Если MAX не принял ответ на callback — отправляем обычным сообщением,
                // иначе пользователь останется без реакции на нажатие
                if ($sender->answer($callbackId, $text, $attachments)) {
                    return;
                }

                if ($userId !== null) {
                    $sender->send($userId, $text, $attachments);
                } elseif ($chatId !== null) {
                    $sender->sendToChat($chatId, $text, $attachments);
                }
            });

            return response('ok');
        }

        if ($userId !== null) {
            defer(fn () => app(MaxMessageSender::class)->send($userId, $text, $attachments));
        } elseif ($chatId !== null) {
            defer(fn () => app(MaxMessageSender::class)->sendToChat($chatId, $text, $attachments));
        }

        return response('ok');
    }
}
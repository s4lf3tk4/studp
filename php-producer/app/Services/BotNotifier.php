<?php

namespace App\Services;

/** Сообщение пользователю по данным заказа: нужно там, где нет объекта события (уведомление об оплате) */
final class BotNotifier
{
    public function send(string $platform, int $recipientId, string $text): void
    {
        if ($platform === 'max') {
            app(MaxMessageSender::class)->send($recipientId, $text);

            return;
        }

        app(VkMessageSender::class)->send($recipientId, $text);
    }
}
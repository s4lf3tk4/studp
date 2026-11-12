<?php

namespace App\Jobs;

use App\Services\MaxMessageSender;
use App\Services\VkMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Доставка сообщения в мессенджер отдельным процессом, а не в вебхуке */
final class SendBotReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 30;

    public function __construct(
        public readonly string $platform,        // vk | max
        public readonly string $text,
        public readonly ?array $keyboard = null,
        public readonly ?int $peerId = null,     // VK: куда писать
        public readonly ?int $userId = null,     // MAX: личка
        public readonly ?int $chatId = null,     // MAX: беседа
    ) {
        $this->onQueue('replies');
    }

    public static function vk(int $peerId, string $text, ?array $keyboard = null): self
    {
        return new self('vk', $text, $keyboard, peerId: $peerId);
    }

    public static function max(?int $userId, ?int $chatId, string $text, ?array $keyboard = null): self
    {
        return new self('max', $text, $keyboard, userId: $userId, chatId: $chatId);
    }

    public function handle(VkMessageSender $vk, MaxMessageSender $max): void
    {
        $attachments = $this->keyboard === null ? null : [$this->keyboard];

        $sent = match (true) {
            $this->platform === 'vk' && $this->peerId !== null => $vk->send($this->peerId, $this->text, $this->keyboard),
            $this->platform === 'max' && $this->userId !== null => $max->send($this->userId, $this->text, $attachments),
            $this->platform === 'max' && $this->chatId !== null => $max->sendToChat($this->chatId, $this->text, $attachments),
            default => false,
        };

        if (! $sent) {
            throw new RuntimeException('Не отправил сообщение: '.$this->platform);
        }
    }
}
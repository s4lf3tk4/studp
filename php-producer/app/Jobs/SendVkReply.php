<?php

namespace App\Jobs;

use App\Services\VkMessageSender;
use App\Services\VkUserResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendVkReply implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $peerId,
        public readonly string $text,
        public readonly ?int $userId = null,
    ) {}

    public function handle(VkMessageSender $sender, VkUserResolver $users): void
    {
        $name = $this->userId !== null ? $users->name($this->userId) : null;

        // Здесь будет логика бота. Пока эхо — чтобы было видно, что цепочка живая.
        $reply = $name !== null
            ? "{$name}, вы написали: {$this->text}"
            : $this->text;

        if (! $sender->send($this->peerId, $reply)) {
            Log::warning('VK: ответ не отправлен', ['peer_id' => $this->peerId]);
        }
    }
}

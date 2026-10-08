<?php

namespace App\Jobs;

use App\Services\MaxMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendMaxReply implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $text,
        public readonly ?string $name = null,
    ) {}

    public function handle(MaxMessageSender $sender): void
    {
        // Здесь будет логика бота. Пока эхо — чтобы было видно, что цепочка живая.
        $reply = $this->name !== null
            ? "{$this->name}, вы написали: {$this->text}"
            : $this->text;

        if (! $sender->send($this->userId, $reply)) {
            Log::warning('MAX: ответ не отправлен', ['user_id' => $this->userId]);
        }
    }
}
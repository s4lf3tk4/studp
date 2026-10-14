<?php

namespace App\Jobs;

use App\Services\DocumentPageCounter;
use App\Services\DocumentStore;
use App\Services\MaxMessageSender;
use App\Services\VkMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ProcessIncomingDocument implements ShouldQueue
{
    use Queueable;

    /** Скачивание + подсчёт: меньше retry_after (REDIS_QUEUE_RETRY_AFTER=300) */
    public int $timeout = 280;
    public int $tries = 2;
    public int $backoff = 10;

    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(
        public readonly string $platform,       // 'max' | 'vk'
        public readonly int $recipientId,       // MAX: user_id, VK: peer_id
        public readonly string $fileUrl,
        public readonly ?string $fileName = null,
        public readonly ?string $extension = null,
        public readonly ?int $size = null,
        public readonly ?int $userId = null,     // кто отправил (VK: from_id)
        public readonly ?string $campus = null,
    ) {}

    public function handle(DocumentPageCounter $counter): void
    {
        // Дешёвые проверки до скачивания — не тянем мегабайты зря
        $extension = strtolower((string) ($this->extension ?: pathinfo((string) $this->fileName, PATHINFO_EXTENSION)));

        if ($extension !== 'pdf') {
            $this->reply('Принимаю только PDF. В Word: «Файл → Сохранить как → PDF»');

            return;
        }

        if ($this->size !== null && $this->size > self::MAX_BYTES) {
            $this->reply('Файл больше '.intdiv(self::MAX_BYTES, 1048576).' МБ');

            return;
        }

        $command = 'curl -sS -m 300 --fail -L';

        if ($this->platform === 'max') {
            $command .= ' -H '.escapeshellarg('Authorization: '.(string) config('services.max.access_token'));
        }

        $command .= ' '.escapeshellarg($this->fileUrl);

        try {
            $pages = $counter->countFromCommand($command);
        } catch (RuntimeException $e) {
            Log::warning('Подсчёт страниц не удался', [
                'platform' => $this->platform,
                'error' => $e->getMessage(),
            ]);

            $this->reply('Не смог посчитать: '.$e->getMessage());

            return;
        }

        Log::info('Файл обработан', [
            'platform' => $this->platform,
            'user_id' => $this->userId,
            'campus' => $this->campus,
            'pages' => $pages,
        ]);

        if ($this->userId !== null) {
            app(DocumentStore::class)->pages($this->platform, $this->userId, $pages);
        }
        $this->reply(sprintf('В файле %d стр.', $pages));
    }

    /** Вызывается после исчерпания попыток */
    public function failed(\Throwable $e): void
    {
        Log::error('Обработка файла не удалась', [
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);

        $this->reply('Не удалось обработать файл: '.$e->getMessage());
    }

    private function reply(string $text): void
    {
        $sender = $this->platform === 'max'
            ? app(MaxMessageSender::class)
            : app(VkMessageSender::class);

        $sender->send($this->recipientId, $text);
    }
}
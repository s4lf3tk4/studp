<?php

namespace App\Jobs;

use App\Services\DocumentStore;
use App\Services\PhotoInbox;
use App\Services\Vk\VkMessageFetcher;
use App\Services\VkMessageSender;
use App\Services\Webhook\Vk\VkKeyboard;
use App\Support\BotTexts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Скачивает фото из сообщения (1–10) и дописывает их в сбор атомарно */
final class CollectIncomingPhotos implements ShouldQueue
{
    use Queueable;

    /** 50 страниц на заказ */
    public const MAX_PAGES = 50;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 120;

    /**
     * @param  array<int, string>  $urls  то, что пришло в событии
     */
    public function __construct(
        public readonly string $platform,
        public readonly int $recipientId,
        public readonly int $userId,
        public readonly array $urls,
        public readonly int $date,
        public readonly string $campus,
        public readonly int $messageId = 0,
        public readonly int $groupId = 0,
    ) {
        $this->onQueue('photos');
    }

    public function handle(PhotoInbox $inbox): void
    {
        $inbox = app(PhotoInbox::class);

        // Callback урезает вложения до одного — добираем полный список из API
        $urls = $this->fullUrls();

        $current = $inbox->count($this->platform, $this->userId);

        if ($current + count($urls) > self::MAX_PAGES) {
            $this->reply(BotTexts::photosLimit(self::MAX_PAGES));

            return;
        }

        $disk = Storage::disk('documents');
        $saved = [];

        try {
            foreach ($urls as $url) {
                $path = sprintf('%s/photos/%s/%s.jpg', $this->platform, now()->format('Y/m'), (string) Str::uuid());

                $disk->makeDirectory(dirname($path));
                $absolute = $disk->path($path);

                $this->download($url, $absolute);
                $saved[] = $path;
            }
        } catch (RuntimeException $e) {
            foreach ($saved as $path) {
                $disk->delete($path);
            }

            Log::warning('Фото не скачались', [
                'platform' => $this->platform,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            $this->reply(BotTexts::photosFailed());

            return;
        }

        // Черновик нужен, чтобы на «Подтвердить» знать куда отвечать и какой корпус
        if ($inbox->count($this->platform, $this->userId) === 0) {
            app(DocumentStore::class)->remember(
                platform: $this->platform,
                userId: $this->userId,
                recipientId: $this->recipientId,
                doc: ['url' => '', 'title' => 'Фото'],
                campus: $this->campus,
            );
        }

        $inbox->push($this->platform, $this->userId, $saved, $this->date);

        $total = $inbox->count($this->platform, $this->userId);

        Log::channel('stderr')->info('Фото добавлены в сбор', [
            'platform' => $this->platform,
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
            'event_urls' => count($this->urls),
            'used_urls' => count($urls),
            'batch' => count($saved),
            'total' => $total,
        ]);

        $this->reply(BotTexts::photosCollected(count($saved), $total), VkKeyboard::photoCollect());
    }

    /** @return array<int, string> */
    private function fullUrls(): array
    {
        $fromApi = app(VkMessageFetcher::class)->photoUrls($this->messageId, $this->groupId);

        if (count($fromApi) > count($this->urls)) {
            return $fromApi;
        }

        return $this->urls;
    }

    private function download(string $url, string $absolutePath): void
    {
        $timeout = (int) config('services.documents.download_timeout', 150);

        $command = 'curl -sS -m '.$timeout.' --connect-timeout 10 -L -o '.escapeshellarg($absolutePath)
            .' -w '.escapeshellarg('%{http_code}')
            .' -H '.escapeshellarg('User-Agent: Mozilla/5.0 (compatible; studpechat/1.0)')
            .' '.escapeshellarg($url);

        $output = [];
        $code = 0;

        exec($command, $output, $code);

        $status = (int) trim(implode('', $output));

        if ($code !== 0 || $status >= 400 || ! is_file($absolutePath) || filesize($absolutePath) < 1024) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw new RuntimeException('не скачал фото (HTTP '.$status.', curl '.$code.')');
        }
    }

    private function reply(string $text, ?array $keyboard = null): void
    {
        app(VkMessageSender::class)->send($this->recipientId, $text, $keyboard);
    }
}
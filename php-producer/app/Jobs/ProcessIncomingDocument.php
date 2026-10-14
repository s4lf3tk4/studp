<?php

namespace App\Jobs;

use App\Models\Campus;
use App\Services\DocumentPageCounter;
use App\Services\DocumentStore;
use App\Services\MaxMessageSender;
use App\Services\VkMessageSender;
use App\Services\Webhook\Max\MaxKeyboard;
use App\Services\Webhook\Vk\VkKeyboard;
use App\Support\BotTexts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Файл скачиваем один раз и оставляем себе: подписанная ссылка VK/MAX
        // рано или поздно перестанет открываться, а для печати нужны байты
        $disk = Storage::disk('documents');
        $path = sprintf('%s/%s/%s.pdf', $this->platform, now()->format('Y/m'), (string) Str::uuid());

        $disk->makeDirectory(dirname($path));   // vk/2026/10 — иначе curl не запишет файл
        $absolute = $disk->path($path);

        try {
            $this->download($absolute);
            $pages = $counter->countFromFile($absolute);
        } catch (RuntimeException $e) {
            $disk->delete($path);   // не оставляем обрывок и файл без посчитанных страниц

            Log::warning('Файл не обработан', [
                'platform' => $this->platform,
                'error' => $e->getMessage(),
            ]);

            $this->reply('Не смог обработать файл: '.$e->getMessage());

            return;
        }

        $campus = $this->campus !== null
            ? Campus::query()->where('code', $this->campus)->first()
            : null;

        if ($this->campus !== null && $campus === null) {
            Log::warning('Корпус из заявки не найден в БД — считаю без цены', ['campus' => $this->campus]);
        }

        // 0 в БД означает «цена не задана» — тогда показываем только страницы
        $pricePerPage = ($campus?->price_per_page_kopecks) ?: null;

        Log::info('Файл обработан', [
            'platform' => $this->platform,
            'user_id' => $this->userId,
            'campus' => $this->campus,
            'pages' => $pages,
            'price_per_page_kopecks' => $pricePerPage,
            'file_path' => $path,
        ]);

        // Черновик заказа: из него кнопка «Подтвердить» создаст запись в MySQL
        if ($this->userId !== null) {
            app(DocumentStore::class)->file($this->platform, $this->userId, $path, $disk->size($path));
            app(DocumentStore::class)->pages($this->platform, $this->userId, $pages, $pricePerPage);
        }

        $keyboard = $this->platform === 'max' ? MaxKeyboard::confirmOrCancel() : VkKeyboard::confirmOrCancel();

        $this->reply(BotTexts::pages($pages, $pricePerPage), $keyboard);
    }

    /** Скачивание в файл: для MAX нужен заголовок Authorization */
    private function download(string $absolutePath): void
    {
        $command = 'curl -sS -m 300 --fail -L -o '.escapeshellarg($absolutePath);

        if ($this->platform === 'max') {
            $command .= ' -H '.escapeshellarg('Authorization: '.(string) config('services.max.access_token'));
        }

        $command .= ' '.escapeshellarg($this->fileUrl);

        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('не скачал файл (код curl '.$code.')');
        }
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

    /** Ответ пользователю: у MAX клавиатура — элемент вложений, у VK — отдельный параметр */
    private function reply(string $text, ?array $keyboard = null): void
    {
        if ($this->platform === 'max') {
            app(MaxMessageSender::class)->send($this->recipientId, $text, $keyboard === null ? null : [$keyboard]);

            return;
        }

        app(VkMessageSender::class)->send($this->recipientId, $text, $keyboard);
    }
}
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
use App\Support\PrintMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ProcessIncomingDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;
    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $platform,
        public readonly int $recipientId,
        public readonly string $fileUrl,
        public readonly ?string $fileName = null,
        public readonly ?string $extension = null,
        public readonly ?int $size = null,
        public readonly ?int $userId = null,  
        public readonly ?string $campus = null,
    ) {}

    public function handle(DocumentPageCounter $counter): void
    {
        // Дешёвые проверки до скачивания — не тянем мегабайты зря
        $extension = strtolower((string) ($this->extension ?: pathinfo((string) $this->fileName, PATHINFO_EXTENSION)));

        if (! in_array($extension, ['pdf', 'docx'], true)) {
            $this->reply(BotTexts::wrongFormat());

            return;
        }

        if ($this->size !== null && $this->size > $this->maxBytes()) {
            $this->reply(BotTexts::tooBig(intdiv($this->maxBytes(), 1048576)));

            return;
        }

        // Файл скачиваем один раз и оставляем себе: подписанная ссылка VK/MAX
        // рано или поздно перестанет открываться, а для печати нужны байты
        $disk = Storage::disk('documents');
        $path = sprintf('%s/%s/%s.%s', $this->platform, now()->format('Y/m'), (string) Str::uuid(), $extension);

        $disk->makeDirectory(dirname($path));
        $absolute = $disk->path($path);

        try {
            $this->download($absolute);

            // Платформа может прислать неверное расширение — сверяем с содержимым
            $realExtension = $this->detectRealExtension($absolute, $extension);

            if ($realExtension !== $extension) {
                Log::notice('Расширение не совпало с содержимым', [
                    'platform' => $this->platform,
                    'declared' => $extension,
                    'real' => $realExtension,
                    'file_path' => $path,
                ]);

                // Переименовываем файл, чтобы админка видела правильный тип
                $newPath = (string) preg_replace('/\.[a-z0-9]+$/i', '.'.$realExtension, $path);
                $disk->move($path, $newPath);

                $path = $newPath;
                $absolute = $disk->path($path);
                $extension = $realExtension;
            }

            $pages = $extension === 'docx'
                ? $counter->countFromDocx($absolute)
                : $counter->countFromFile($absolute);
        } catch (RuntimeException $e) {
            $disk->delete($path);

            $transient = str_starts_with($e->getMessage(), 'не скачал файл');
            $hasTries = $this->attempts() < $this->tries;

            Log::warning('Файл не обработан', [
                'platform' => $this->platform,
                'attempt' => $this->attempts(),
                'transient' => $transient,
                'error' => $e->getMessage(),
            ]);

            // Сетевой сбой — повторяем; битый файл повторять бессмысленно
            if ($transient && $hasTries) {
                $this->release($this->backoff);

                return;
            }

            // DOCX без метаданных (экспорт из Google Docs и подобное)
            if (str_contains($e->getMessage(), 'нет данных о страницах')) {
                $this->reply(BotTexts::wrongFormat());

                return;
            }

            $this->reply(BotTexts::countFailed($e->getMessage()));

            return;
        }

        // Цена зависит от корпуса и от режима печати — считаем её ДО формирования текста
        $campus = $this->campus !== null
            ? Campus::query()->where('code', $this->campus)->first()
            : null;

        if ($this->campus !== null && $campus === null) {
            Log::warning('Корпус из заявки не найден в БД — считаю без цены', ['campus' => $this->campus]);
        }

        // Режимы с ценой 0 в корпусе не настроены — бот их не предлагает
        $prices = PrintMode::prices($campus);
        $fromDocx = $extension === 'docx';

        Log::info('Файл обработан', [
            'platform' => $this->platform,
            'user_id' => $this->userId,
            'campus' => $this->campus,
            'pages' => $pages,
            'extension' => $extension,
            'modes' => array_keys($prices),
            'file_path' => $path,
        ]);

        // Черновик заказа: из него кнопки режима и «Подтвердить» соберут заказ в MySQL
        if ($this->userId !== null) {
            app(DocumentStore::class)->file($this->platform, $this->userId, $path, $disk->size($path));
            app(DocumentStore::class)->pages($this->platform, $this->userId, $pages);
        }

        // Ни одной цены — показываем только страницы, как было до цветной печати
        if ($prices === []) {
            $this->reply(
                $fromDocx ? BotTexts::pagesFromDocx($pages) : BotTexts::pages($pages),
                $this->confirmKeyboard(),
            );

            return;
        }

        // Цена задана только у одного режима — спрашивать не о чем, подставляем его
        if (count($prices) === 1) {
            $mode = (string) array_key_first($prices);
            $price = $prices[$mode];

            if ($this->userId !== null) {
                app(DocumentStore::class)->mode($this->platform, $this->userId, $mode, $price);
            }

            $this->reply(
                BotTexts::modeConfirmed(PrintMode::label($mode) ?? $mode, $pages, $price, $fromDocx),
                $this->confirmKeyboard(),
            );

            return;
        }

        // Оба режима доступны — клиент выбирает принтер, цену показываем сразу
        $this->reply(
            BotTexts::modeQuestion(
                $pages,
                $prices[PrintMode::BW] ?? null,
                $prices[PrintMode::COLOR] ?? null,
                $fromDocx,
            ),
            $this->modeKeyboard(),
        );
    }

    private function maxBytes(): int
    {
        return (int) config("services.{$this->platform}.max_file_bytes", 30 * 1024 * 1024);
    }

    /** Кнопки после подсчёта страниц: подтверждение заказа или отмена */
    private function confirmKeyboard(): array
    {
        return $this->platform === 'max' ? MaxKeyboard::confirmOrCancel() : VkKeyboard::confirmOrCancel();
    }

    /** Кнопки выбора принтера: появляются, когда в корпусе заданы обе цены */
    private function modeKeyboard(): array
    {
        return $this->platform === 'max' ? MaxKeyboard::printMode() : VkKeyboard::printMode();
    }

    /** Настоящий тип по первым байтам: платформа может прислать неверное расширение */
    private function detectRealExtension(string $absolute, string $declared): string
    {
        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return $declared;
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return 'docx';   // zip-контейнер: docx, xlsx, pptx
        }

        return $declared;
    }

    /** Скачивание в файл: для MAX нужен заголовок Authorization */
    private function download(string $absolutePath): void
    {
        $timeout = (int) config('services.documents.download_timeout', 150);
        $command = 'curl -sS -m '.$timeout.' --connect-timeout 10 -L -o '.escapeshellarg($absolutePath)
            .' -w '.escapeshellarg('%{http_code}');

        if ($this->platform === 'max') {
            $command .= ' -H '.escapeshellarg('Authorization: '.(string) config('services.max.access_token'));
        }

        // VK отдаёт часть ссылок только с браузерным User-Agent
        $command .= ' -H '.escapeshellarg('User-Agent: Mozilla/5.0 (compatible; studpechat/1.0)');
        $command .= ' '.escapeshellarg($this->fileUrl);

        $output = [];
        exec($command, $output, $code);

        $status = (int) trim(implode('', $output));

        if ($code !== 0 || $status >= 400) {
            $prefix = is_file($absolutePath)
                ? substr((string) file_get_contents($absolutePath, false, null, 0, 200), 0, 200)
                : '';

            Log::error('Не удалось скачать файл по ссылке платформы', [
                'platform' => $this->platform,
                'curl_code' => $code,
                'http_status' => $status,
                'url_host' => parse_url($this->fileUrl, PHP_URL_HOST),
                'body_prefix' => $prefix,
            ]);

            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw new RuntimeException(
                'не скачал файл (HTTP '.$status.', curl '.$code.')'
            );
        }
    }

    /** Вызывается после исчерпания попыток */
    public function failed(\Throwable $e): void
    {
        Log::error('Обработка файла не удалась', [
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);

        $this->reply(BotTexts::countFailed($e->getMessage()));
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
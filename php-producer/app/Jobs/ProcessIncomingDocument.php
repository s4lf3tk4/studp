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
use App\Support\PaperFormat;
use App\Support\PriceList;
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
        // Файл уже на диске (PDF, собранный из фото) — скачивать не нужно
        public readonly ?string $localPath = null,
    ) {}

    public function handle(DocumentPageCounter $counter): void
    {
        // Дешёвые проверки до скачивания — не тянем мегабайты зря
        $extension = strtolower((string) ($this->extension ?: pathinfo((string) $this->fileName, PATHINFO_EXTENSION)));

        if (! in_array($extension, ['pdf', 'docx'], true)) {
            $this->reply(BotTexts::wrongFormat());

            return;
        }

        // Лимит размера — только для того, что скачиваем сами: собранный из фото
        // PDF бывает больше 30 МБ, но он уже наш и лежит на диске
        if ($this->localPath === null && $this->size !== null && $this->size > $this->maxBytes()) {
            $this->reply(BotTexts::tooBig(intdiv($this->maxBytes(), 1048576)));

            return;
        }

        $disk = Storage::disk('documents');

        if ($this->localPath !== null) {
            $path = $this->localPath;
            $absolute = $disk->path($path);
        } else {
            $path = sprintf('%s/%s/%s.%s', $this->platform, now()->format('Y/m'), (string) Str::uuid(), $extension);

            $disk->makeDirectory(dirname($path));
            $absolute = $disk->path($path);
        }

        try {
            if ($this->localPath === null) {
                // Файл скачиваем один раз и оставляем себе: подписанная ссылка VK/MAX
                // рано или поздно перестанет открываться, а для печати нужны байты
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
            }

            // Файл на месте и тип известен — только теперь считаем страницы и форматы
            $pages = $extension === 'docx'
                ? $counter->countFromDocx($absolute)
                : $counter->countFromFile($absolute);

            // Форматы страниц: у PDF — постранично из pdfinfo, у DOCX — из w:pgSz по секциям
            $formats = $extension === 'docx'
                ? $counter->formatsFromDocx($absolute, $pages)
                : $counter->formatsFromFile($absolute, $pages);
            
            // Постраничные форматы: у PDF из pdfinfo, у DOCX по секциям (w:pgSz)
            $pageFormats = $extension === 'docx'
                ? $counter->docxPageFormats($absolute, $pages)
                : $counter->pageFormats($absolute, $pages);

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

        // Цена зависит от корпуса, режима печати и формата каждой страницы
        $campus = $this->campus !== null
            ? Campus::query()->where('code', $this->campus)->first()
            : null;

        if ($this->campus !== null && $campus === null) {
            Log::warning('Корпус из заявки не найден в БД — считаю без цены', ['campus' => $this->campus]);
        }

        $prices = PriceList::forCampus($campus);
        $totals = $prices->totals($formats);
        $fromDocx = $extension === 'docx';

        Log::info('Файл обработан', [
            'platform' => $this->platform,
            'user_id' => $this->userId,
            'campus' => $this->campus,
            'pages' => $pages,
            'formats' => $formats,
            'extension' => $extension,
            'modes' => array_keys($totals),
            'file_path' => $path,
        ]);

        // Черновик заказа: из него кнопки режима и «Подтвердить» соберут заказ в MySQL
        if ($this->userId !== null) {
            app(DocumentStore::class)->file($this->platform, $this->userId, $path, $disk->size($path));
            app(DocumentStore::class)->pages($this->platform, $this->userId, $formats, $pageFormats);
        }

        // Формат страницы вне A4…A0: печатать такое не будем
        $unsupported = false;

        foreach (array_keys($formats) as $format) {
            if (! PaperFormat::isSupported((string) $format)) {
                $unsupported = true;

                break;
            }
        }

        if ($unsupported) {
            $this->reply(BotTexts::formatNotSupported());

            return;
        }

        // В корпусе нет ни одной цены — показываем только страницы и форматы
        if ($prices->isEmpty()) {
            $this->reply(BotTexts::pagesOnly($formats, $fromDocx), $this->confirmKeyboard());

            return;
        }

        // Формат поддерживается, но цены на него в корпусе нет
        $missing = $prices->unpriced($formats);

        if ($missing !== []) {
            $this->reply(BotTexts::priceMissing($missing), $this->chosenCampusKeyboard());

            return;
        }

        // Цена есть только у одного режима — спрашивать не о чем
        if (count($totals) === 1) {
            $this->applyMode((string) array_key_first($totals), $formats, $prices, $fromDocx);

            return;
        }

        // Оба режима доступны — клиент выбирает принтер, итог показываем сразу
        $this->reply(BotTexts::modeQuestion($formats, $totals, $fromDocx), $this->modeKeyboard());
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

    /**
     * Единственный доступный режим: сразу считаем и показываем итог.
     *
     * @param  array<string, int>  $formats
     */
    private function applyMode(string $mode, array $formats, PriceList $prices, bool $fromDocx): void
    {
        $total = (int) $prices->total($mode, $formats);
        $perFormat = [];

        foreach (array_keys($formats) as $format) {
            $perFormat[(string) $format] = (int) $prices->price($mode, (string) $format);
        }

        if ($this->userId !== null) {
            app(DocumentStore::class)->mode($this->platform, $this->userId, $mode, $perFormat, $total);
        }

        $this->reply(
                BotTexts::modeConfirmed(...),
                $this->platform === 'max'
                    ? MaxKeyboard::confirmOrCancel()
                    : VkKeyboard::confirmOrPickPages($mode === PrintMode::COLOR),
            );
    }

    /** Кнопки корпусов: нужны, когда этот корпус не может напечатать формат */
    private function chosenCampusKeyboard(): ?array
    {
        return $this->platform === 'max' ? MaxKeyboard::chosenCampus() : VkKeyboard::chosenCampus();
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
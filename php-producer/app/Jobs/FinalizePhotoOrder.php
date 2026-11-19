<?php

namespace App\Jobs;

use App\Services\DocumentPageCounter;
use App\Services\DocumentStore;
use App\Services\Pdf\PhotoPdf;
use App\Services\PhotoInbox;
use App\Services\VkMessageSender;
use App\Support\BotTexts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** «Подтвердить»: собираем все фото в один PDF, чистим сбор и отдаём обычному расчёту */
final class FinalizePhotoOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $backoff = 10;
    public int $timeout = 120;

    public function __construct(
        public readonly string $platform,
        public readonly int $userId,
    ) {
        $this->onQueue('photos');
    }

    public function handle(PhotoInbox $inbox): void
    {
        $inbox = app(PhotoInbox::class);
        $store = app(DocumentStore::class);
        $disk = Storage::disk('documents');

        $paths = $inbox->items($this->platform, $this->userId);
        $draft = $store->get($this->platform, $this->userId);

        // Сбор осиротел: чистим и файлы, и состояние, чтобы не копить мусор
        if ($paths === [] || $draft === null) {
            $this->deletePhotos($disk, $paths);
            $inbox->forget($this->platform, $this->userId);

            $this->reply(BotTexts::orderNotFound());

            return;
        }

        $pdfPath = sprintf('%s/%s/%s.pdf', $this->platform, now()->format('Y/m'), (string) Str::uuid());

        $disk->makeDirectory(dirname($pdfPath));

        try {
            // PDF пишется сразу в файл потоком, в памяти держится только голова картинки
            $size = app(PhotoPdf::class)->build(
                array_map(fn (string $path): string => $disk->path($path), $paths),
                $disk->path($pdfPath),
            );
        } catch (RuntimeException $e) {
            // Файлы не удаляем: пользователь может нажать «Подтвердить» ещё раз
            Log::error('Не собрал PDF из фото', [
                'user_id' => $this->userId,
                'photos' => count($paths),
                'error' => $e->getMessage(),
            ]);

            $this->reply(BotTexts::photosFailed());

            return;
        }

        // Фото больше не нужны: в PDF они уже вложены
        $this->deletePhotos($disk, $paths);
        $inbox->forget($this->platform, $this->userId);

        // Число страниц берём из самого PDF, а не из количества фото
        $pages = app(DocumentPageCounter::class)->countFromFile($disk->path($pdfPath));

        if ($pages !== count($paths)) {
            Log::channel('stderr')->warning('Страниц в PDF не совпало с числом фото', [
                'user_id' => $this->userId,
                'photos' => count($paths),
                'pdf_pages' => $pages,
            ]);
        }

        Log::info('PDF из фото собран', [
            'user_id' => $this->userId,
            'photos' => count($paths),
            'pdf_pages' => $pages,
            'file_path' => $pdfPath,
            'size' => $size,
        ]);

        // Дальше — обычный путь: pdfinfo посчитает страницы, PriceList назовёт цену
        ProcessIncomingDocument::dispatch(
            platform: $this->platform,
            recipientId: (int) ($draft['recipient_id'] ?? 0),
            userId: $this->userId,
            fileUrl: '',
            fileName: 'photos.pdf',
            extension: 'pdf',
            size: $size,
            campus: $draft['campus'] ?? null,
            localPath: $pdfPath,
        );
    }

    /**
     * Удаление исходных фото с диска.
     *
     * @param  array<int, string>  $paths
     */
    private function deletePhotos(\Illuminate\Filesystem\FilesystemAdapter $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning('Не удалил фото из сбора', [
                    'user_id' => $this->userId,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reply(string $text): void
    {
        $draft = app(DocumentStore::class)->get($this->platform, $this->userId);
        $peerId = (int) ($draft['recipient_id'] ?? 0);

        if ($peerId > 0) {
            app(VkMessageSender::class)->send($peerId, $text);
        }
    }
}   
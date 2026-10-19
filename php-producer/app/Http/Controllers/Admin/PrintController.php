<?php

namespace App\Http\Controllers\Admin;

use App\Models\Campus;
use App\Models\Document;
use App\Services\BotNotifier;
use App\Support\BotTexts;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Печать и выдача: оплаченные ждут печати, напечатанные ждут клиента,
 * выданные остаются в истории без файла.
 */
final class PrintController
{
    /** Все корпуса */
    public function queue(): JsonResponse
    {
        return response()->json($this->payload(Document::query(), null));
    }

    /** Один корпус */
    public function campus(Campus $campus): JsonResponse
    {
        $query = Document::query()->where('campus', $campus->code);

        return response()->json($this->payload($query, $campus));
    }

    /** Напечатано: клиент получает уведомление, файл остаётся на месте */
    public function markPrinted(Document $document): JsonResponse
    {
        if ($document->status !== Document::STATUS_PAID) {
            return response()->json(['message' => 'Файл не в очереди на печать'], 409);
        }

        $document->markPrinted();

        return response()->json([
            'ok' => true,
            'id' => $document->id,
            'status' => $document->status,
            'status_label' => $document->statusLabel(),
            'notified' => $this->notifyPrinted($document),
        ]);
    }

    /** Забрали: файл удаляется с диска, запись остаётся в истории */
    public function issued(Document $document): JsonResponse
    {
        if ($document->status !== Document::STATUS_PRINTED) {
            return response()->json(['message' => 'Забрать можно только напечатанный документ'], 409);
        }

        $this->deleteFile($document);

        // Путь обнуляем: файла больше нет, ссылка в интерфейсе станет недоступной
        $document->markIssued();

        return response()->json([
            'ok' => true,
            'id' => $document->id,
            'status' => $document->status,
            'status_label' => $document->statusLabel(),
        ]);
    }

    /** Отдать PDF, пока он ещё существует */
    public function file(Document $document): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk('documents');
        $path = (string) $document->file_path;

        if ($path === '' || ! $disk->exists($path)) {
            return response()->json(['message' => 'Файл не найден'], 404);
        }

        $response = $disk->response($path, $this->fileName($document));

        // Документы приватные: запрещаем кеширование в браузере и на прокси
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @param  Builder<Document>  $query
     * @return array<string, mixed>
     */
    private function payload(Builder $query, ?Campus $campus): array
    {
        $titles = Campus::query()->pluck('title', 'code');

        $documents = $query
            ->whereIn('status', [Document::STATUS_PAID, Document::STATUS_PRINTED, Document::STATUS_ISSUED])
            ->with('payments')
            // сначала ждут печати, потом напечатанные, в конце выданные
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [
                Document::STATUS_PAID,
                Document::STATUS_PRINTED,
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $disk = Storage::disk('documents');

        $files = $documents->map(function (Document $document) use ($titles, $disk): array {
            $payment = $document->payments->firstWhere('status', 'paid')
                ?? $document->payments->sortByDesc('id')->first();

            $amount = (int) ($payment?->amount ?? 0);
            $path = (string) $document->file_path;

            return [
                'id' => $document->id,
                'name' => $this->fileName($document),
                'campus' => $titles[$document->campus] ?? ($document->campus ?? '—'),
                'campus_code' => $document->campus,
                'pages' => (int) $document->pages,
                'amount_kopecks' => $amount,
                'amount' => Money::rub($amount),
                'size' => $this->humanSize((int) $document->size_bytes),
                'created_at' => $document->created_at?->format('d.m.Y H:i'),
                'status' => $document->status,
                'status_label' => $document->statusLabel(),
                // после выдачи файла нет — ссылку не показываем
                'file_available' => $path !== '' && $disk->exists($path),
                'file_url' => route('admin.print.file', $document, false),
            ];
        })->all();

        return [
            'campus' => $campus === null ? null : [
                'id' => $campus->id,
                'code' => $campus->code,
                'title' => $campus->title,
            ],
            'files' => $files,
            'total_pages' => array_sum(array_column($files, 'pages')),
            'total_amount' => Money::rub(array_sum(array_column($files, 'amount_kopecks'))),
        ];
    }

    /** Удаление файла: ошибка не должна мешать отметить заказ выданным */
    private function deleteFile(Document $document): void
    {
        $path = (string) $document->file_path;

        if ($path === '') {
            return;
        }

        try {
            $disk = Storage::disk('documents');

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            Log::error('Не удалось удалить файл выданного заказа', [
                'document_id' => $document->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Сообщение клиенту: что напечатано, куда и в какой кабинет идти */
    private function notifyPrinted(Document $document): bool
    {
        $campus = Campus::query()->where('code', $document->campus)->first();

        $recipient = (int) ($document->chat_id ?? $document->external_user_id ?? 0);

        if ($recipient <= 0) {
            Log::warning('Некуда отправить уведомление о печати', ['document_id' => $document->id]);

            return false;
        }

        $text = BotTexts::orderPrinted(
            $document->id,
            $this->fileName($document),
            (int) $document->pages,
            $campus?->title ?? 'типография',
            $campus?->address,
            $campus?->room,
        );

        try {
            $sent = app(BotNotifier::class)->send((string) $document->platform, $recipient, $text);

            if (! $sent) {
                Log::warning('Платформа не приняла уведомление о печати', [
                    'document_id' => $document->id,
                    'platform' => $document->platform,
                ]);
            }

            return $sent;
        } catch (\Throwable $e) {
            Log::error('Не удалось отправить уведомление о печати', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function fileName(Document $document): string
    {
        $name = trim((string) ($document->original_name ?? ''));

        return $name !== '' ? $name : 'document-'.$document->id.'.pdf';
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', ' ').' МБ';
        }

        return max(1, (int) round($bytes / 1024)).' КБ';
    }
}
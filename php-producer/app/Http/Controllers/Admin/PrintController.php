<?php

namespace App\Http\Controllers\Admin;

use App\Models\Campus;
use App\Models\Document;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\BotNotifier;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;

/**
 * Очередь печати: оплаченные файлы, ожидающие печати.
 * Файлы лежат вне public, поэтому отдаются только этим маршрутом под сессией админа.
 */
final class PrintController
{
    /** Очередь по всем корпусам */
    public function queue(): JsonResponse
    {
        return response()->json($this->payload(Document::query(), null));
    }

    /** Очередь по выбранному корпусу */
    public function campus(Campus $campus): JsonResponse
    {
        $query = Document::query()->where('campus', $campus->code);

        return response()->json($this->payload($query, $campus));
    }


    /** Отметить файл напечатанным: строка уходит из очереди, клиент получает уведомление */
    public function markPrinted(Document $document): JsonResponse
    {
        if ($document->status !== Document::STATUS_PAID) {
            return response()->json(['message' => 'Файл не в очереди на печать'], 409);
        }

        $document->markPrinted();

        return response()->json([
            'ok' => true,
            'id' => $document->id,
            'notified' => $this->notifyPrinted($document),
        ]);
    }
        /** Сообщение клиенту: что напечатано, куда и в какой кабинет идти */
    private function notifyPrinted(Document $document): bool
    {
        $campus = Campus::query()->where('code', $document->campus)->first();

        // Тот же получатель, что и у уведомления об оплате
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
            // Документ уже отмечен напечатанным: уведомление — не повод откатывать статус
            Log::error('Не удалось отправить уведомление о печати', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Отдать PDF: открывается во встроенном просмотрщике браузера */
    public function file(Document $document): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk('documents');

        if ($document->file_path === null || ! $disk->exists($document->file_path)) {
            return response()->json(['message' => 'Файл не найден'], 404);
        }

        $response = $disk->response($document->file_path, $this->fileName($document));

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
            ->where('status', Document::STATUS_PAID)
            ->with('payments')
            ->latest('id')
            ->limit(200)
            ->get();

        $files = $documents->map(function (Document $document) use ($titles): array {
            // Платёж именно этой строки: сначала оплаченный, иначе последний
            $payment = $document->payments->firstWhere('status', 'paid')
                ?? $document->payments->sortByDesc('id')->first();

            $amount = (int) ($payment?->amount ?? 0);

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
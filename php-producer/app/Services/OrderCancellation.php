<?php

namespace App\Services;

use App\Models\Document;
use App\Support\BotTexts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Отмена: неоплаченный заказ удаляется, оплаченный отменить нельзя */
final class OrderCancellation
{
    public function __construct(private readonly DocumentStore $store) {}

    public function cancel(string $platform, int $userId): string
    {
        $draft = $this->store->get($platform, $userId);

        // Черновика нет — кнопку нажали на экране выбора корпуса
        if ($draft === null) {
            return BotTexts::cancelBtn();
        }

        if (isset($draft['document_id'])) {
            return $this->cancelOrder($platform, $userId, (int) $draft['document_id']);
        }

        if (isset($draft['file_path'])) {
            $this->deleteFile((string) $draft['file_path']);
        }

        $this->store->forget($platform, $userId);

        return BotTexts::fileCanceled();
    }

    private function cancelOrder(string $platform, int $userId, int $documentId): string
    {
        $document = Document::query()->find($documentId);

        if ($document === null) {
            $this->store->forget($platform, $userId);

            return BotTexts::fileCanceled();
        }

        // Деньги приняты или документ уже в работе — только через типографию
        if (in_array($document->status, [Document::STATUS_PAID, Document::STATUS_PRINTED, Document::STATUS_ISSUED], true)) {
            return BotTexts::orderCancelForbidden($document->id);
        }

        $path = (string) $document->file_path;

        try {
            DB::transaction(function () use ($document): void {
                $document->payments()->delete();
                $document->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Не удалил отменённый заказ', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return BotTexts::orderFailed();
        }

        if ($path !== '') {
            $this->deleteFile($path);
        }

        $this->store->forget($platform, $userId);

        return BotTexts::orderCanceled($documentId);
    }

    private function deleteFile(string $path): void
    {
        try {
            Storage::disk('documents')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Не удалил файл отменённого заказа', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
<?php

namespace App\Services;

use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Отмена черновика: файл уходит с диска, запись — из Redis */
final class OrderCancellation
{
    public function __construct(private readonly DocumentStore $store) {}

    public function cancel(string $platform, int $userId): string
    {
        $draft = $this->store->get($platform, $userId);

        // Черновика нет — значит кнопку нажали на экране выбора корпуса
        if ($draft === null) {
            return BotTexts::cancelBtn();
        }

        // Заказ уже создан в MySQL: кнопкой его не отменить
        if (isset($draft['document_id'])) {
            return BotTexts::orderCannotCancel((int) $draft['document_id']);
        }

        if (isset($draft['file_path'])) {
            try {
                Storage::disk('documents')->delete($draft['file_path']);
            } catch (\Throwable $e) {
                // Файла может уже не быть — это не повод не отвечать пользователю
                Log::warning('Не удалил файл отменённого заказа', [
                    'path' => $draft['file_path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->store->forget($platform, $userId);

        return BotTexts::fileCanceled();
    }
}
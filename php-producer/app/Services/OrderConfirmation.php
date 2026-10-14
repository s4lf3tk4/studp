<?php

namespace App\Services;

use App\Models\Document;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Подтверждение заказа: черновик из Redis превращается в запись в MySQL */
final class OrderConfirmation
{
    public function __construct(private readonly DocumentStore $store) {}

    /** Возвращает текст ответа пользователю */
    public function confirm(string $platform, int $userId): string
    {
        $draft = $this->store->get($platform, $userId);

        if ($draft === null || ! isset($draft['file_path'], $draft['pages'])) {
            return BotTexts::orderNotFound();
        }

        // Повторное нажатие «Подтвердить» не создаёт второй заказ
        if (isset($draft['document_id'])) {
            return BotTexts::alreadyConfirmed((int) $draft['document_id'], $draft['payment_url'] ?? null);
        }

        try {
            $document = Document::create([
                'platform' => $platform,
                'external_user_id' => $userId,
                'chat_id' => $draft['recipient_id'] ?? null,
                'original_name' => $draft['title'] ?? null,
                'mime' => 'application/pdf',
                'size_bytes' => $draft['size'] ?? 0,
                'file_path' => $draft['file_path'],
                'campus' => $draft['campus'] ?? null,
                'pages' => $draft['pages'],
                'status' => Document::STATUS_AWAITING_PAYMENT,
            ]);

            $paymentUrl = null;

            if (isset($draft['amount_kopecks'], $draft['price_per_page_kopecks'])) {
                $payment = $document->payments()->create([
                    'uuid' => (string) Str::uuid(),
                    'pages' => $draft['pages'],
                    'unit_price' => $draft['price_per_page_kopecks'],
                    'amount' => $draft['amount_kopecks'],
                    'currency' => 'RUB',
                    'status' => 'pending',
                    'provider' => config('services.payments.provider', 'stub'),
                ]);

                $paymentUrl = $this->paymentUrl($payment->uuid);
            }
        } catch (\Throwable $e) {
            Log::error('Не создал заказ из черновика', [
                'platform' => $platform,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return BotTexts::orderFailed();
        }

        $this->store->confirmed($platform, $userId, $document->id, $paymentUrl);

        return BotTexts::orderConfirmed($document->id, $paymentUrl);
    }

    /** Заглушка платёжной ссылки: настоящий провайдер подставит свой адрес */
    private function paymentUrl(string $uuid): string
    {
        return rtrim((string) config('services.payments.public_url'), '/').'/pay/'.$uuid;
    }
}
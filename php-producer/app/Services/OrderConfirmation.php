<?php

namespace App\Services;

use App\Models\Document;
use App\Services\Payments\PaymentManager;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Подтверждение заказа: черновик из Redis становится заказом в MySQL и счётом у провайдера */
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
        } catch (\Throwable $e) {
            Log::error('Не создал заказ из черновика', [
                'platform' => $platform,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return BotTexts::orderFailed();
        }

        $paymentUrl = null;

        try {
            $paymentUrl = $this->createPayment($document, $draft);
        } catch (\Throwable $e) {
            // Заказ уже в БД, деньги не списаны: ссылку можно сформировать повторно,
            // поэтому пользователю отдаём заказ без ссылки, а не «не смог создать»
            Log::error('Не создал счёт у провайдера', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->store->confirmed($platform, $userId, $document->id, $paymentUrl);

        return BotTexts::orderConfirmed(
            $document->id,
            (string) ($draft['title'] ?? 'файл'),
            (int) $draft['pages'],
            (int) ($draft['amount_kopecks'] ?? 0),
            $paymentUrl,
        );
    }

    /**
     * Счёт создаётся, только если у корпуса задана цена.
     *
     * @param  array<string, mixed>  $draft
     */
    private function createPayment(Document $document, array $draft): ?string
    {
        if (! isset($draft['amount_kopecks'], $draft['price_per_page_kopecks'])) {
            return null;
        }

        $gateway = app(PaymentManager::class)->default();

        $payment = $document->payments()->create([
            'uuid' => (string) Str::uuid(),
            'pages' => $draft['pages'],
            'unit_price' => $draft['price_per_page_kopecks'],
            'amount' => $draft['amount_kopecks'],
            'currency' => 'RUB',
            'status' => 'pending',
            'provider' => $gateway->name(),
        ]);

        $url = $gateway->createPayment($payment);

        $payment->update(['confirmation_url' => $url]);

        return $url;
    }
}
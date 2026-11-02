<?php

namespace App\Services;

use App\Models\Document;
use App\Services\Payments\TinkoffGateway;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Подтверждение заказа: черновик из Redis становится заказом в MySQL и счётом в Т-Банке */
final class OrderConfirmation
{
    /** В этих статусах деньги уже приняты или документ напечатан */
    private const PAID_STATUSES = [
        Document::STATUS_PAID,
        Document::STATUS_PRINTED,
        Document::STATUS_ISSUED,
    ];

    public function __construct(private readonly DocumentStore $store) {}

    public function confirm(string $platform, int $userId): ConfirmationResult
    {
        $draft = $this->store->get($platform, $userId);

        if ($draft === null || ! isset($draft['file_path'], $draft['pages'])) {
            return ConfirmationResult::text(BotTexts::orderNotFound());
        }

        if (isset($draft['document_id'])) {
            return $this->repeat($platform, $userId, $draft);
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

            return ConfirmationResult::text(BotTexts::orderFailed());
        }

        [$paymentUrl, $paymentFailed] = $this->link($document, $draft);

        $this->store->confirmed($platform, $userId, $document->id, $paymentUrl);

        $text = BotTexts::orderConfirmed(
            $document->id,
            (string) ($draft['title'] ?? 'файл'),
            (int) $draft['pages'],
            (int) ($draft['amount_kopecks'] ?? 0),
            $paymentUrl,
        );

        if ($paymentFailed) {
            $text .= "\n\n".BotTexts::paymentUnavailable();
        }

        return new ConfirmationResult($text, $paymentFailed, $paymentUrl);
    }

    /** @param  array<string, mixed>  $draft */
    private function repeat(string $platform, int $userId, array $draft): ConfirmationResult
    {
        $documentId = (int) $draft['document_id'];
        $storedUrl = (string) ($draft['payment_url'] ?? '');

        if ($storedUrl !== '') {
            return new ConfirmationResult(
                BotTexts::alreadyConfirmed($documentId, $storedUrl),
                false,
                $storedUrl,
            );
        }

        $document = Document::query()->find($documentId);

        if ($document === null) {
            $this->store->forget($platform, $userId);

            return ConfirmationResult::text(BotTexts::orderNotFound());
        }

        if (in_array($document->status, self::PAID_STATUSES, true)) {
            return new ConfirmationResult(BotTexts::alreadyConfirmed($documentId));
        }

        [$paymentUrl, $paymentFailed] = $this->link($document, $draft);
        $this->store->confirmed($platform, $userId, $documentId, $paymentUrl);

        $text = BotTexts::alreadyConfirmed($documentId, $paymentUrl);

        if ($paymentFailed) {
            $text .= "\n\n".BotTexts::paymentUnavailable();
        }

        return new ConfirmationResult($text, $paymentFailed, $paymentUrl);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{0: ?string, 1: bool}
     */
    private function link(Document $document, array $draft): array
    {
        if (! isset($draft['amount_kopecks'], $draft['price_per_page_kopecks'])) {
            return [null, false];   // цена у корпуса не задана — счёт не нужен
        }

        try {
            return [$this->createPayment($document, $draft), false];
        } catch (\Throwable $e) {
            Log::error('Не создал счёт у провайдера', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return [null, true];
        }
    }

    /** @param  array<string, mixed>  $draft */
    private function createPayment(Document $document, array $draft): string
    {
        $gateway = app(TinkoffGateway::class);

        $payment = $document->payments()->where('status', 'pending')->latest('id')->first();

        if ($payment === null) {
            $payment = $document->payments()->create([
                'uuid' => (string) Str::uuid(),
                'pages' => $draft['pages'],
                'unit_price' => $draft['price_per_page_kopecks'],
                'amount' => $draft['amount_kopecks'],
                'currency' => 'RUB',
                'status' => 'pending',
                'provider' => $gateway->name(),
            ]);
        }

        return $gateway->createPayment($payment);
    }
}
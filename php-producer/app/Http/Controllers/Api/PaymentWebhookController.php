<?php

namespace App\Http\Controllers\Api;

use App\Models\Document;
use App\Models\Payment;
use App\Services\BotNotifier;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentNotification;
use App\Support\BotTexts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Приём уведомлений платёжного провайдера: POST /api/payments/{provider}/notify */
final class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentManager $manager, string $provider): Response
    {
        if (! $this->secretIsValid($request)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $gateway = $manager->gateway($provider);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => 'unknown_provider'], 404);
        }

        $payload = $request->all();
        $notification = $gateway->parseNotification($payload);

        if ($notification === null) {
            Log::warning('Платёжное уведомление не разобрано', ['provider' => $provider, 'payload' => $payload]);

            return response()->json(['ok' => false, 'error' => 'bad_payload'], 422);
        }

        $payment = $this->findPayment($notification);

        if ($payment === null) {
            Log::warning('Платёжное уведомление по неизвестному счёту', [
                'provider' => $provider,
                'provider_payment_id' => $notification->providerPaymentId,
            ]);

            // Отвечаем 200: иначе провайдер будет повторять уведомление бесконечно
            return response()->json(['ok' => true, 'found' => false]);
        }

        $payment->update([
            'provider_payment_id' => $notification->providerPaymentId,
            'notification' => $notification->raw,
        ]);

        if ($notification->paid) {
            $this->markPaid($payment, $notification);
        }

        return response()->json(['ok' => true]);
    }

    private function findPayment(PaymentNotification $notification): ?Payment
    {
        if ($notification->providerPaymentId !== '') {
            $payment = Payment::query()
                ->where('provider_payment_id', $notification->providerPaymentId)
                ->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        if ($notification->documentId !== null) {
            return Payment::query()
                ->where('document_id', $notification->documentId)
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function markPaid(Payment $payment, PaymentNotification $notification): void
    {
        $document = $payment->document;

        if ($document === null || $document->status === Document::STATUS_PAID) {
            return;   // повторное уведомление ничего не меняет
        }

        $document->markPaid($notification->providerPaymentId);

        $recipient = (int) ($document->chat_id ?? $document->external_user_id ?? 0);

        if ($recipient > 0) {
            app(BotNotifier::class)->send($document->platform, $recipient, BotTexts::orderPaid($document->id));
        }
    }

    private function secretIsValid(Request $request): bool
    {
        $secret = (string) config('services.payments.webhook_secret');

        return $secret === '' || hash_equals($secret, (string) $request->header('X-Payment-Secret'));
    }
}
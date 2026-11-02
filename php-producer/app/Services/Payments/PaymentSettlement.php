<?php

namespace App\Services\Payments;

use App\Models\Document;
use App\Models\Payment;
use App\Services\BotNotifier;
use App\Support\BotTexts;
use Illuminate\Support\Facades\Log;

final class PaymentSettlement
{
    /** Возвращает true, если статус действительно изменился */
    public function markPaid(Payment $payment, ?string $providerPaymentId = null): bool
    {
        $document = $payment->document;

        if ($document === null) {
            Log::warning('Оплата по счёту без заказа', ['payment_id' => $payment->id]);

            return false;
        }

        if ($document->status === Document::STATUS_PAID) {
            return false;   // повторное уведомление ничего не меняет
        }

        $providerPaymentId = $providerPaymentId ?: (string) $payment->provider_payment_id;

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'provider_payment_id' => $providerPaymentId,
        ]);

        $document->markPaid($providerPaymentId);

        $recipient = (int) ($document->chat_id ?? $document->external_user_id ?? 0);

        if ($recipient > 0) {
            app(BotNotifier::class)->send(
                $document->platform,
                $recipient,
                BotTexts::orderPaid($document->id),
            );
        }

        Log::info('Заказ оплачен', [
            'document_id' => $document->id,
            'payment_id' => $payment->id,
            'provider_payment_id' => $providerPaymentId,
        ]);

        return true;
    }
}
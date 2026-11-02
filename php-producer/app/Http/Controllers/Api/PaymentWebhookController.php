<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Services\Payments\PaymentNotification;
use App\Services\Payments\PaymentSettlement;
use App\Services\Payments\TinkoffGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Уведомления Т-Банка об оплате: POST /api/payments/tinkoff/notify */
final class PaymentWebhookController
{
    public function __invoke(
        Request $request,
        TinkoffGateway $gateway,
        PaymentSettlement $settlement,
    ): Response {
        $payload = $request->all();
        $notification = $gateway->parseNotification($payload);

        if ($notification === null) {
            Log::warning('T-Bank: уведомление не прошло проверку', ['payload' => $payload]);

            // Подпись не сходится: повторять бессмысленно, но и «OK» отдавать нельзя
            return response('', 403);
        }

        $payment = $this->findPayment($notification);

        if ($payment === null) {
            Log::warning('T-Bank: уведомление по неизвестному счёту', [
                'provider_payment_id' => $notification->providerPaymentId,
                'document_id' => $notification->documentId,
            ]);

            // 200 OK: иначе банк будет повторять уведомление сутками
            return response('OK');
        }

        if ($notification->amountKopecks !== null && (int) $payment->amount !== $notification->amountKopecks) {
            Log::error('T-Bank: сумма в уведомлении не совпадает со счётом', [
                'payment_id' => $payment->id,
                'expected' => (int) $payment->amount,
                'got' => $notification->amountKopecks,
            ]);

            return response('', 409);
        }

        $payment->update([
            'provider_payment_id' => $notification->providerPaymentId,
            'notification' => $notification->raw,
        ]);

        if ($notification->paid) {
            $settlement->markPaid($payment, $notification->providerPaymentId);
        }

        return response('OK');
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
}
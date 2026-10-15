<?php

namespace App\Services\Payments;

use App\Models\Payment;

/** Заглушка: ссылка ведёт на нашу страницу /pay/<uuid>, оплату имитирует команда payments:paid */
final class StubGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stub';
    }

    public function createPayment(Payment $payment): string
    {
        return rtrim((string) config('services.payments.public_url'), '/').'/pay/'.$payment->uuid;
    }

    public function parseNotification(array $payload): ?PaymentNotification
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : $payload;
        $id = $object['id'] ?? $object['provider_payment_id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $value = $object['amount']['value'] ?? null;
        $documentId = $object['metadata']['document_id'] ?? null;

        return new PaymentNotification(
            providerPaymentId: $id,
            paid: (bool) ($object['paid'] ?? false) || ($payload['event'] ?? null) === 'payment.succeeded',
            amountKopecks: is_string($value) ? (int) round(((float) $value) * 100) : null,
            documentId: is_numeric($documentId) ? (int) $documentId : null,
            raw: $payload,
        );
    }
}
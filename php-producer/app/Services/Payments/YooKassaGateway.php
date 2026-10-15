<?php

namespace App\Services\Payments;

use App\Models\Payment;
use RuntimeException;

/** ЮKassa: пока только разбор уведомлений, создание платежа — следующий шаг */
final class YooKassaGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'yookassa';
    }

    public function createPayment(Payment $payment): string
    {
        // TODO: POST https://api.yookassa.ru/v3/payments
        //   авторизация: Basic base64(shop_id:secret_key)
        //   заголовок:   Idempotence-Key: <uuid>
        //   тело:        amount, capture, description, confirmation{type:redirect, return_url},
        //                metadata{document_id}, receipt{customer, items[]}
        //   ответ:       id + confirmation.confirmation_url
        throw new RuntimeException('ЮKassa не настроена: нужны YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY');
    }

    public function parseNotification(array $payload): ?PaymentNotification
    {
        $object = $payload['object'] ?? null;

        if (! is_array($object) || ! isset($object['id'])) {
            return null;
        }

        $value = $object['amount']['value'] ?? null;
        $documentId = $object['metadata']['document_id'] ?? null;

        return new PaymentNotification(
            providerPaymentId: (string) $object['id'],
            paid: ($object['status'] ?? null) === 'succeeded' && ($object['paid'] ?? false) === true,
            amountKopecks: is_string($value) ? (int) round(((float) $value) * 100) : null,
            documentId: is_numeric($documentId) ? (int) $documentId : null,
            raw: $payload,
        );
    }
}
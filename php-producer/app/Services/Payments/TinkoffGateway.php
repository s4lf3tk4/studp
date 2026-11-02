<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class TinkoffGateway implements PaymentGateway
{
    public function __construct(private readonly TinkoffClient $client) {}

    public function name(): string
    {
        return 'tinkoff';
    }

    public function createPayment(Payment $payment): string
    {
        $response = $this->client->init([
            'Amount' => (int) $payment->amount,
            'OrderId' => self::orderIdFor($payment),
            'Description' => Str::limit('Услуги печати, заказ '.(string) $payment->document_id, 140, ''),
            'PayType' => (string) config('services.payments.tinkoff.pay_type', 'O'),
            'NotificationURL' => (string) config('services.payments.tinkoff.notification_url'),
            'SuccessURL' => (string) config('services.payments.tinkoff.success_url'),
            'FailURL' => (string) config('services.payments.tinkoff.fail_url'),
            'RedirectDueDate' => now()
                ->addMinutes(max(5, (int) config('services.payments.tinkoff.link_ttl_minutes', 60)))
                ->toIso8601String(),
        ]);

        $url = (string) ($response['PaymentURL'] ?? '');

        if ($url === '') {
            throw new RuntimeException('T-Bank Init: в ответе нет PaymentURL');
        }

        $payment->update([
            'provider' => $this->name(),
            'provider_payment_id' => (string) ($response['PaymentId'] ?? ''),
            'confirmation_url' => $url,
        ]);

        Log::info('T-Bank: счёт создан', [
            'payment_id' => $payment->id,
            'document_id' => $payment->document_id,
            'provider_payment_id' => $response['PaymentId'] ?? null,
            'amount' => $payment->amount,
        ]);

        return $url;
    }

    public function parseNotification(array $payload): ?PaymentNotification
    {
        if ((string) ($payload['TerminalKey'] ?? '') !== $this->client->terminalKey()) {
            Log::warning('T-Bank: уведомление с чужим TerminalKey', ['got' => $payload['TerminalKey'] ?? null]);

            return null;
        }

        if (! $this->client->verify($payload)) {
            Log::warning('T-Bank: неверная подпись уведомления', ['order_id' => $payload['OrderId'] ?? null]);

            return null;
        }

        $status = strtoupper((string) ($payload['Status'] ?? ''));
        $success = ($payload['Success'] ?? false) === true || ($payload['Success'] ?? '') === 'true';

        return new PaymentNotification(
            providerPaymentId: (string) ($payload['PaymentId'] ?? ''),
            paid: $success && in_array($status, ['CONFIRMED', 'AUTHORIZED'], true),
            amountKopecks: isset($payload['Amount']) ? (int) $payload['Amount'] : null,
            documentId: self::documentIdFromOrder((string) ($payload['OrderId'] ?? '')),
            raw: $payload,
        );
    }

    /** Статус напрямую из банка — страховка, если уведомление не дошло */
    public function status(string $paymentId): array
    {
        $data = $this->client->state($paymentId);
        $status = strtoupper((string) ($data['Status'] ?? ''));

        return [
            'status' => $status,
            'paid' => in_array($status, ['CONFIRMED', 'AUTHORIZED'], true),
            'amount' => isset($data['Amount']) ? (int) $data['Amount'] : null,
        ];
    }

    public static function orderIdFor(Payment $payment): string
    {
        return sprintf('ord-%d-%d', (int) $payment->document_id, (int) $payment->id);
    }

    public static function documentIdFromOrder(string $orderId): ?int
    {
        return preg_match('/^ord-(\d+)-\d+$/', $orderId, $m) === 1 ? (int) $m[1] : null;
    }
}
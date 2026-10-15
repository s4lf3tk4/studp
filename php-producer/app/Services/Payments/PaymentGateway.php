<?php

namespace App\Services\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    /** Имя провайдера, как оно приходит в URL уведомления */
    public function name(): string;

    /** Создать платёж у провайдера, вернуть ссылку на оплату */
    public function createPayment(Payment $payment): string;

    /** Разобрать уведомление; null — формат не распознан */
    public function parseNotification(array $payload): ?PaymentNotification;
}
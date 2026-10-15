<?php

namespace App\Services\Payments;

/** Разобранное уведомление провайдера — в общем виде, без привязки к конкретному API */
final readonly class PaymentNotification
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $providerPaymentId,
        public bool $paid,
        public ?int $amountKopecks = null,
        public ?int $documentId = null,
        public array $raw = [],
    ) {}
}
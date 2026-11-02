<?php

namespace App\Services;

/** Итог подтверждения заказа: текст для клиента и признак сбоя платёжного сервиса */
final readonly class ConfirmationResult
{
    public function __construct(
        public string $text,
        public bool $paymentFailed = false,
        public ?string $paymentUrl = null,
    ) {}

    public static function text(string $text): self
    {
        return new self($text);
    }
}
<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/** Выбор платёжного провайдера по конфигу */
final class PaymentManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct()
    {
        $this->gateways = [
            'stub' => new StubGateway(),
            'yookassa' => new YooKassaGateway(),
        ];
    }

    public function default(): PaymentGateway
    {
        return $this->gateway((string) config('services.payments.provider', 'stub'));
    }

    public function gateway(string $name): PaymentGateway
    {
        if (! isset($this->gateways[$name])) {
            throw new InvalidArgumentException("Неизвестный платёжный провайдер: {$name}");
        }

        return $this->gateways[$name];
    }
}
<?php

namespace App\Services\Webhook;

use Symfony\Component\HttpFoundation\Response;

interface WebhookHandler
{
    /** Источник, который обслуживает обработчик: 'vk' | 'max' */
    public function source(): string;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): Response;
}
<?php

namespace App\Services\Webhook;

use App\Services\Webhook\Max\MaxWebhookHandler;
use App\Services\Webhook\Vk\VkWebhookHandler;
use Symfony\Component\HttpFoundation\Response;

final class WebhookRouter
{
    /** @var array<string, WebhookHandler> */
    private array $handlers;

    public function __construct(VkWebhookHandler $vk, MaxWebhookHandler $max)
    {
        // Новая платформа = новый класс + строка здесь
        $this->handlers = [
            $vk->source() => $vk,
            $max->source() => $max,
        ];
    }

    public function handle(array $payload, ?string $source): Response
    {
        if ($source === null || ! isset($this->handlers[$source])) {
            return response()->json(['ok' => false, 'error' => 'unknown_source']);
        }

        return $this->handlers[$source]->handle($payload);
    }
}
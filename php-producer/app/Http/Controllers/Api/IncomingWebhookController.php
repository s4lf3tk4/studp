<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MessageSourceDetector;
use App\Services\Webhook\WebhookRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class IncomingWebhookController extends Controller
{
    public function __construct(
        private readonly MessageSourceDetector $detector,
        private readonly WebhookRouter $router,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->all();
        $source = $this->detector->detect($payload);

        if (! $this->secretIsValid($request, $payload, $source)) {
            Log::warning('Webhook отклонён: неверный secret', ['source' => $source]);

            return response()->json(['ok' => false, 'error' => 'invalid_secret'], 403);
        }

        return $this->router->handle($payload, $source);
    }

    /**
     * Проверка подлинности запроса. Включается только для той платформы,
     * у которой соответствующий ключ задан в .env; иначе запрос пропускается.
     */
    private function secretIsValid(Request $request, array $payload, ?string $source): bool
    {
        if ($source === 'vk') {
            $secret = (string) config('services.vk.secret_key');

            return $secret === ''
                || hash_equals($secret, (string) ($payload['secret'] ?? ''));
        }

        if ($source === 'max') {
            $secret = (string) config('services.max.webhook_secret');

            return $secret === ''
                || hash_equals($secret, (string) $request->header('X-Max-Bot-Api-Secret'));
        }

        return true;
    }
}
<?php

namespace App\Services\Webhook;

final class StartCommand
{
    /**
     * Кнопка «Начать»: VK кладёт в message.payload {"command":"start"},
     * MAX присылает событие bot_started. Обе платформы понимают и /start.
     */
    public static function matches(array $message, ?string $text): bool
    {
        $payload = $message['payload'] ?? null;

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : ['command' => $payload];
        }

        $command = is_array($payload) ? ($payload['command'] ?? null) : null;

        if (is_string($command) && in_array(mb_strtolower($command), ['start', 'начать'], true)) {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $text)), ['начать', '/start', 'start'], true);
    }
}
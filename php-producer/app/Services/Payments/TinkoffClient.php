<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Клиент Т-Банк EACQ: подпись Token и вызовы методов. Все значения — из config/environment. */
final class TinkoffClient
{
    public function __construct(
        private readonly string $terminalKey,
        private readonly string $password,
        private readonly string $baseUrl,
        private readonly int $timeout = 20,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('services.payments.tinkoff', []);

        $client = new self(
            terminalKey: trim((string) ($config['terminal_key'] ?? '')),
            password: (string) ($config['password'] ?? ''),
            baseUrl: rtrim((string) ($config['base_url'] ?? 'https://securepay.tinkoff.ru/v2'), '/'),
            timeout: max(5, (int) ($config['timeout'] ?? 20)),
        );

        if ($client->terminalKey === '' || $client->password === '') {
            throw new RuntimeException('T-Bank: не заданы TINKOFF_TERMINAL_KEY / TINKOFF_TERMINAL_PASSWORD');
        }

        return $client;
    }

    public function terminalKey(): string
    {
        return $this->terminalKey;
    }

    /** Проверка подписи входящего уведомления — пароль терминала наружу не отдаём */
    public function verify(array $payload): bool
    {
        return hash_equals(
            TinkoffSignature::make($payload, $this->password),
            (string) ($payload['Token'] ?? ''),
        );
    }

    public function init(array $payload): array
    {
        return $this->call('Init', $payload);
    }

    public function state(string $paymentId): array
    {
        return $this->call('GetState', ['PaymentId' => $paymentId]);
    }

    public function cancel(array $payload): array
    {
        return $this->call('Cancel', $payload);
    }

    private function call(string $method, array $payload): array
    {
        $payload['TerminalKey'] = $this->terminalKey;
        $payload['Token'] = TinkoffSignature::make($payload, $this->password);

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/'.$method, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'T-Bank %s: HTTP %d %s',
                $method,
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        $data = (array) $response->json();

        if (($data['Success'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                'T-Bank %s отказал: %s (код %s)',
                $method,
                (string) ($data['Message'] ?? 'без описания'),
                (string) ($data['ErrorCode'] ?? '—'),
            ));
        }

        return $data;
    }
}
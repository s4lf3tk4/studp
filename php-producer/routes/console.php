<?php

use App\Models\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

use App\Models\Payment;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentSettlement;
use App\Services\Payments\TinkoffClient;
use App\Services\Payments\TinkoffGateway;

Artisan::command('payments:paid {document} {--status=CONFIRMED}', function () {
    $document = Document::query()->find((int) $this->argument('document'));

    if ($document === null) {
        $this->error('Заказ #'.$this->argument('document').' не найден');

        return;
    }

    $payment = $document->payments()->latest('id')->first();

    if ($payment === null) {
        $this->error('У заказа #'.$document->id.' нет счёта');

        return;
    }

    $client = TinkoffClient::fromConfig();
    $status = strtoupper((string) $this->option('status'));

    $payload = [
        'TerminalKey' => $client->terminalKey(),
        'OrderId' => TinkoffGateway::orderIdFor($payment),
        'Success' => $status === 'CONFIRMED',
        'Status' => $status,
        'PaymentId' => $payment->provider_payment_id !== null && $payment->provider_payment_id !== ''
            ? $payment->provider_payment_id
            : (string) random_int(100000000, 999999999),
        'ErrorCode' => $status === 'CONFIRMED' ? '0' : '100',
        'Amount' => (int) $payment->amount,
    ];
    $payload['Token'] = TinkoffSignature::make(
        $payload,
        (string) config('services.payments.tinkoff.password'),
    );

    $base = rtrim((string) config('services.payments.webhook_url'), '/');

    $response = Http::asJson()->post($base, $payload);

    $this->line("POST {$base} → HTTP {$response->status()} {$response->body()}");
})->purpose('Имитировать уведомление Т-Банка об оплате (для локальной проверки)');

Artisan::command('payments:check {--minutes=10} {--limit=50}', function () {
    $gateway = app(TinkoffGateway::class);

    if (! $gateway instanceof TinkoffGateway) {
        $this->error('Провайдер не Т-Банк — проверка статусов не поддерживается');

        return;
    }

    $settlement = app(PaymentSettlement::class);
    $checked = 0;
    $paid = 0;

    $payments = Payment::query()
        ->where('status', 'pending')
        ->whereNotNull('provider_payment_id')
        ->where('created_at', '<', now()->subMinutes((int) $this->option('minutes')))
        ->with('document')
        ->limit((int) $this->option('limit'))
        ->get();

    foreach ($payments as $payment) {
        $checked++;

        try {
            $state = $gateway->status((string) $payment->provider_payment_id);
        } catch (\Throwable $e) {
            $this->warn("счёт {$payment->id}: {$e->getMessage()}");

            continue;
        }

        if ($state['paid']) {
            $settlement->markPaid($payment, (string) $payment->provider_payment_id);
            $paid++;
            $this->line("счёт {$payment->id}: оплачен (подтверждено через GetState)");
        }
    }

    $this->info("проверено {$checked}, оплачено {$paid}");
})->purpose('Сверить статусы неоплаченных счетов напрямую в Т-Банке');

Schedule::command('payments:check')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('documents:list {--status=} {--limit=20}', function () {
    $documents = Document::query()
        ->when($this->option('status'), fn ($query, $status) => $query->where('status', $status))
        ->with('payments')
        ->latest('id')
        ->limit((int) $this->option('limit'))
        ->get();

    if ($documents->isEmpty()) {
        $this->info('Заказов нет');

        return;
    }

    $this->table(
        ['id', 'платформа', 'пользователь', 'корпус', 'стр.', 'сумма', 'статус', 'оплачен', 'создан', 'файл'],
        $documents->map(function (Document $document) {
            $payment = $document->payments->sortByDesc('id')->first();

            return [
                $document->id,
                $document->platform,
                $document->external_user_id,
                $document->campus ?? '—',
                $document->pages ?? '—',
                $payment !== null ? number_format($payment->amount / 100, 2, ',', ' ').' ₽' : '—',
                $document->status,
                $payment?->paid_at?->format('d.m H:i') ?? '—',
                $document->created_at?->format('d.m H:i'),
                $document->file_path,
            ];
        })->all(),
    );
})->purpose('Показать заказы: что оплачено, а что нет');

Artisan::command('documents:prune {--unpaid-days=1} {--paid-days=0} {--days=7} {--dry-run}', function () {
    $disk = Storage::disk('documents');
    $dry = (bool) $this->option('dry-run');
    $unpaidDays = (int) $this->option('unpaid-days');
    $paidDays = (int) $this->option('paid-days');
    $orders = 0;
    $files = 0;
    $orphans = 0;

    // Неоплаченные: клиент не довёл заказ до оплаты — держать нечего
    $query = Document::query()->where(fn ($sub) => $sub
        ->whereIn('status', [Document::STATUS_NEW, Document::STATUS_COUNTED, Document::STATUS_AWAITING_PAYMENT])
        ->where('created_at', '<', now()->subDays($unpaidDays)));

    // Оплаченные, но не напечатанные: по умолчанию НЕ трогаем.
    // Это заказ, который типография ещё должна клиенту; 0 = хранить бессрочно.
    if ($paidDays > 0) {
        $query->orWhere(fn ($sub) => $sub
            ->where('status', Document::STATUS_PAID)
            ->where('created_at', '<', now()->subDays($paidDays)));
    }

    // printed и issued — архив: записи не удаляются никогда
    foreach ($query->get() as $document) {
        $this->line("заказ #{$document->id} ({$document->status}) → {$document->file_path}");
        $orders++;

        if ($dry) {
            continue;
        }

        $path = $document->file_path;

        // Сначала строка: если её удаление сорвётся, файл подберёт проход «сирот»
        $document->delete();

        if ($path === null) {
            continue;
        }

        try {
            if ($disk->exists($path)) {
                $disk->delete($path);
                $files++;
            }
        } catch (\Throwable $e) {
            // Не роняем уборку из-за одного файла: его добьёт проход ниже
            \Illuminate\Support\Facades\Log::error('Не удалось удалить файл заказа', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Сироты: PDF, на который нет записи в БД. Файлы напечатанных заказов
    // защищены: их пути есть в file_path, значит попадают в $known.
    $known = array_flip(Document::query()->whereNotNull('file_path')->pluck('file_path')->all());
    $limit = now()->subDays((int) $this->option('days'))->getTimestamp();

    foreach ($disk->allFiles() as $path) {
        if (! str_ends_with($path, '.pdf') || isset($known[$path]) || $disk->lastModified($path) > $limit) {
            continue;
        }

        $this->line("сирота: {$path}");
        $orphans++;

        if ($dry) {
            continue;
        }

        try {
            $disk->delete($path);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Не удалось удалить файл-сироту', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $this->info(sprintf(
        '%s: заказов %d, файлов %d, сирот %d',
        $dry ? 'будет удалено' : 'удалено',
        $orders,
        $files,
        $orphans,
    ));
})->purpose('Удалить неоплаченные заказы и файлы без записей в БД');

Schedule::command('documents:prune')->dailyAt('04:00')->withoutOverlapping();
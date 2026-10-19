<?php

use App\Models\Document;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('payments:paid {document} {--provider=}', function () {
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

    $provider = (string) ($this->option('provider') ?: $payment->provider);
    $base = rtrim((string) config('services.payments.webhook_url'), '/');
    $secret = (string) config('services.payments.webhook_secret');

    // Полезная нагрузка в том же виде, в каком её пришлёт провайдер
    $payload = [
        'event' => 'payment.succeeded',
        'object' => [
            'id' => $payment->provider_payment_id ?: 'stub-'.$payment->uuid,
            'status' => 'succeeded',
            'paid' => true,
            'amount' => [
                'value' => number_format($payment->amount / 100, 2, '.', ''),
                'currency' => $payment->currency,
            ],
            'metadata' => [
                'document_id' => $document->id,
                'payment_uuid' => $payment->uuid,
            ],
        ],
    ];

    $response = Http::withHeaders($secret !== '' ? ['X-Payment-Secret' => $secret] : [])
        ->post($base.'/'.$provider.'/notify', $payload);

    $this->line("POST {$base}/{$provider}/notify → HTTP {$response->status()} {$response->body()}");
})->purpose('Имитировать успешную оплату: отправить уведомление провайдера');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Заглушка платёжной страницы: сюда ведёт ссылка от StubGateway
Route::get('/pay/{uuid}', function (string $uuid) {
    $payment = Payment::query()->where('uuid', $uuid)->firstOrFail();
    $document = $payment->document;

    return response(
        "Заглушка оплаты\n"
        .'Заказ №'.$payment->document_id."\n"
        .'Файл: '.($document?->original_name ?? '—')."\n"
        .'Страниц: '.($document?->pages ?? '—')."\n"
        .'К оплате: '.number_format($payment->amount / 100, 2, ',', ' ').' ₽'."\n"
        .'Статус: '.$payment->status."\n\n"
        .'Имитировать оплату: docker compose exec -u www-data app php artisan payments:paid '.$payment->document_id,
        200,
        ['Content-Type' => 'text/plain; charset=utf-8'],
    );
})->name('pay');
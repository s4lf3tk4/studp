<?php

use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\PrintController;

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

/*
| Панель типографии.
| Статику (Vue-сборку) отдаёт nginx из public/admin напрямую — здесь только API.
*/
Route::prefix('admin')->group(function (): void {
    Route::post('/login', [AdminSessionController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('admin.login');
    Route::post('/logout', [AdminSessionController::class, 'logout'])->name('admin.logout');

    Route::middleware('admin')->prefix('api')->group(function (): void {
        Route::get('/state', [ScheduleController::class, 'state'])->name('admin.state');
        Route::put('/campuses/{campus}', [ScheduleController::class, 'updateCampus'])->name('admin.campuses.update');
        Route::put('/campuses/{campus}/days', [ScheduleController::class, 'updateDays'])->name('admin.campuses.days');
        Route::get('/print', [PrintController::class, 'queue'])->name('admin.print.queue');
        Route::get('/print/campuses/{campus}', [PrintController::class, 'campus'])->name('admin.print.campus');
        Route::get('/print/{document}/file', [PrintController::class, 'file'])->name('admin.print.file');
        Route::post('/print/{document}/printed', [PrintController::class, 'markPrinted'])->name('admin.print.printed');
        });
});
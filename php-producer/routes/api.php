<?php

use App\Http\Controllers\Api\IncomingWebhookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentWebhookController;

Route::post('/payments/tinkoff/notify', PaymentWebhookController::class)->name('payments.notify');
Route::post('/webhooks/incoming', IncomingWebhookController::class)->name('webhooks.incoming'); 
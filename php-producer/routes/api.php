<?php

use App\Http\Controllers\Api\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/incoming', IncomingWebhookController::class)->name('webhooks.incoming'); 
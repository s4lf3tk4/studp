<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
// Route::get('/pay/{payment:uuid}', [StubPaymentController::class, 'show']);
// Route::post('/pay/{payment:uuid}/confirm', [StubPaymentController::class, 'confirm']);

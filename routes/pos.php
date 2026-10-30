<?php

use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->middleware(['module:pos', 'module:inventory'])->controller(PosController::class)->group(function () {
    Route::post('/currency-drawers','currencyDrawer');
    Route::post('/currency-drawers/{id}/count','countCurrencyDrawer');
    Route::post('/quote', 'quote');
    Route::post('/sales', 'checkout');
    Route::get('/sales/{id}', 'sale');
    Route::post('/sales/{id}/return-quote', 'returnQuote');
    Route::post('/sales/{id}/returns', 'refund');
    Route::post('/shifts', 'open');
    Route::post('/shifts/{id}/close', 'close');
    Route::post('/holds', 'hold');
    Route::post('/holds/{id}/cancel', 'cancelHold');
    Route::post('/customers', 'customer');
    Route::get('/customers/{id}/loyalty', 'customerLoyalty');
});

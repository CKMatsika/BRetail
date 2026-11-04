<?php

use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module:accounting', 'module:inventory'])->prefix('trade')->controller(TradeController::class)->group(function () {
    Route::get('/data', 'index');
    Route::get('/customers/{id}', 'customer');
    Route::patch('/customers/{id}', 'updateCustomer');
    Route::delete('/customers/{id}', 'deleteCustomer');
    Route::get('/history/{id}', 'history');
    Route::get('/print/{kind}/{id}', 'print')->where('kind', 'order|document');
    Route::post('/{action}/{id?}', 'action')->where('action', 'suppliers|orders|approve|cancel|receipts|bills|customers|credits|quote-preview|quotes|invoices|invoice-preview|invoice-edits|invoice-details|settlements|returns|openings');
});

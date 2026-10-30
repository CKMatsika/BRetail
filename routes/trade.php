<?php

use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module:accounting', 'module:inventory'])->prefix('trade')->controller(TradeController::class)->group(function () {
    Route::get('/data', 'index');
    Route::get('/print/{kind}/{id}', 'print')->where('kind', 'order|document');
    Route::post('/{action}/{id?}', 'action')->where('action', 'suppliers|orders|approve|cancel|receipts|bills|customers|credits|quotes|invoices|settlements|returns|openings');
});

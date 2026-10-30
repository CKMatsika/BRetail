<?php

use App\Http\Controllers\InternationalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module:currencies', 'module:accounting', 'module:inventory'])->prefix('international')->controller(InternationalController::class)->group(function () {
    Route::get('/data', 'index');
    Route::get('/transfers/{id}/print', 'printTransfer');
    Route::post('/{action}/{id?}', 'action')->where('action', 'currencies|rates|approve-rate|accounts|entries|revalue|reverse|transfers|approve-transfer|cancel-transfer|dispatch|receive|loss');
});

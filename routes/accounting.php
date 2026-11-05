<?php

use App\Http\Controllers\AccountingController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:accounting')->prefix('accounting')->controller(AccountingController::class)->group(function () {
    Route::get('/data', 'index');
    Route::get('/reports', 'reports');
    Route::get('/export', 'export');
    Route::get('/statements/{id}', 'statement');
    Route::post('/{action}/{id?}', 'action')->where('action', 'setup|activate|accounts|mappings|periods|lock|journals|reverse|cashbook|cashbook-correct|cashbook-reverse|purchases|banks|statements|match|unmatch|close|cancel');
});

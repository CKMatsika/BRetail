<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

// Included inside authenticated tenant-scoped web/API groups.
Route::prefix('inventory')->middleware('module:inventory')->controller(InventoryController::class)->group(function () {
    Route::post('/products', 'product');
    Route::patch('/products/{id}', 'product');
    Route::post('/products/{product}/variants', 'variant');
    Route::patch('/products/{product}/variants/{id}', 'variant');
    Route::post('/classifications', 'classification');
    Route::post('/warehouses', 'warehouse');
    Route::post('/movements', 'movement');
    Route::post('/counts', 'count');
    Route::post('/counts/{id}/post', 'finishCount');
    Route::post('/counts/{id}/cancel', 'cancelCount');
    Route::post('/warehouses/{id}/reconcile', 'reconcile');
    Route::post('/import', 'import');
    Route::get('/export', 'export');
    Route::get('/template', 'template');
});

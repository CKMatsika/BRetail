<?php

use App\Http\Controllers\HrController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant', 'module:hr'])->group(function () {
    Route::get('/hr', [HrController::class, 'page']);
    Route::post('/hr/employees', [HrController::class, 'store']);
    Route::patch('/hr/employees/{id}', [HrController::class, 'update']);
    Route::get('/hr/employees/{id}/history', [HrController::class, 'history']);
    Route::get('/hr/nssa/p4', [HrController::class, 'exportNssaP4']);
    Route::get('/hr/leave-calendar', [HrController::class, 'leaveCalendar']);
    Route::post('/hr/leave-types', [HrController::class, 'storeLeaveType']);
    Route::post('/hr/leave-requests', [HrController::class, 'requestLeave']);
    Route::patch('/hr/leave-requests/{id}', [HrController::class, 'decideLeave']);
});


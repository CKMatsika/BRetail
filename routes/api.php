<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\HrController;
use App\Http\Controllers\PayrollController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', \App\Http\Middleware\RestrictDeviceTokens::class, 'tenant', 'throttle:60,1'])->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('module:inventory');
    require __DIR__.'/inventory.php';
    require __DIR__.'/pos.php';
    require __DIR__.'/accounting.php';
    require __DIR__.'/trade.php';
    require __DIR__.'/international.php';
    Route::get('/hr', [HrController::class, 'index'])->middleware('module:hr');
    Route::post('/hr/employees', [HrController::class, 'store'])->middleware('module:hr');
    Route::patch('/hr/employees/{id}', [HrController::class, 'update'])->middleware('module:hr');
    Route::get('/hr/employees/{id}/history', [HrController::class, 'history'])->middleware('module:hr');
    Route::get('/hr/leave-calendar', [HrController::class, 'leaveCalendar'])->middleware('module:hr');
    Route::post('/hr/leave-types', [HrController::class, 'storeLeaveType'])->middleware('module:hr');
    Route::post('/hr/leave-requests', [HrController::class, 'requestLeave'])->middleware('module:hr');
    Route::patch('/hr/leave-requests/{id}', [HrController::class, 'decideLeave'])->middleware('module:hr');
    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('module:payroll');
    Route::post('/payroll/rules', [PayrollController::class, 'storeRule'])->middleware('module:payroll');
    Route::patch('/payroll/rules/{id}/approve', [PayrollController::class, 'approveRule'])->middleware('module:payroll');
    Route::post('/payroll/employees/{employeeId}/compensation', [PayrollController::class, 'storeCompensation'])->middleware('module:payroll');
    Route::post('/payroll/runs', [PayrollController::class, 'storeRun'])->middleware('module:payroll');
    Route::patch('/payroll/runs/{id}/approve', [PayrollController::class, 'approveRun'])->middleware('module:payroll');
    Route::post('/payroll/runs/{id}/post', [PayrollController::class, 'postRun'])->middleware('module:payroll');
    Route::post('/payroll/runs/{id}/reverse', [PayrollController::class, 'reverseRun'])->middleware('module:payroll');
    Route::post('/payroll/runs/{id}/settle', [PayrollController::class, 'settleRun'])->middleware('module:payroll');
    Route::get('/accounting', [AccountingController::class, 'index'])->middleware('module:accounting');
    Route::get('/pos', [PosController::class, 'index'])->middleware(['module:pos', 'module:inventory']);
    Route::get('/me', fn (Request $r) => ['data' => ['user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name]])->middleware('module:access');
    Route::post('/devices/heartbeat', [\App\Http\Controllers\DeviceController::class, 'heartbeat'])->middleware('module:access');
    Route::get('/branches', [WorkspaceController::class, 'apiBranches'])->middleware('module:branches');
    Route::get('/branches/{id}', [WorkspaceController::class, 'apiBranch'])->middleware('module:branches');
});

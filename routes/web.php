<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? (auth()->user()->isPlatformStaff() ? '/platform/organizations' : '/dashboard') : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginPage'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'registerPage']);
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/subscription', [\App\Http\Controllers\SubscriptionStatusController::class, 'show'])->middleware('auth');
Route::middleware(['auth', 'platform'])->prefix('platform')->group(function () {
    Route::get('/organizations', [\App\Http\Controllers\PlatformOrganizationController::class, 'index']);
    Route::patch('/organizations/{tenantId}/subscription', [\App\Http\Controllers\PlatformOrganizationController::class, 'updateSubscription']);
});
Route::middleware(['auth', 'tenant'])->group(base_path('routes/inventory.php'));
Route::middleware(['auth', 'tenant'])->group(base_path('routes/pos.php'));
Route::middleware(['auth', 'tenant'])->group(base_path('routes/accounting.php'));
Route::middleware(['auth', 'tenant'])->group(base_path('routes/trade.php'));
foreach (['purchasing', 'sales', 'backoffice'] as $section) {
    Route::get('/'.$section, [TradeController::class, 'page'])->defaults('section', $section)->middleware(['auth', 'tenant', 'module:'.$section, 'module:accounting', 'module:inventory']);
}
Route::get('/accounting', [AccountingController::class, 'page'])->middleware(['auth', 'tenant', 'module:accounting']);
Route::middleware(['auth', 'tenant', 'module:loyalty'])->controller(LoyaltyController::class)->group(function () {
    Route::get('/loyalty', 'page');
    Route::patch('/loyalty/program', 'program');
    Route::post('/loyalty/promotions', 'promotion');
    Route::patch('/loyalty/promotions/{id}', 'promotion');
    Route::post('/loyalty/customers/{id}/enroll', 'enroll');
    Route::post('/loyalty/customers/{id}/withdraw', 'withdraw');
});
Route::middleware(['auth', 'tenant', 'module:pos', 'module:inventory'])->controller(PosController::class)->group(function () {
    Route::get('/pos', 'page');
    Route::get('/pos/sales/{id}/receipt', 'receipt');
    Route::get('/pos/returns/{id}/receipt', 'returnReceipt');
});
Route::get('/inventory', [InventoryController::class, 'page'])->middleware(['auth', 'tenant', 'module:inventory']);
Route::middleware(['auth', 'tenant'])->controller(WorkspaceController::class)->group(function () {
    Route::post('/company/logo', [CompanyLogoController::class, 'store'])->middleware('module:administration');
    Route::get('/company/logo', [CompanyLogoController::class, 'show']);
    foreach (['dashboard' => 'dashboard', 'branches' => 'branches', 'team' => 'access', 'company' => 'administration', 'modules' => 'administration', 'audit' => 'administration', 'tenders' => 'administration'] as $section => $module) {
        Route::get('/'.$section, 'page')->defaults('section', $section)->middleware('module:'.$module);
    }
    Route::post('/branches', 'storeBranch')->middleware('module:branches');
    Route::patch('/branches/{id}', 'updateBranch')->middleware('module:branches');
    Route::post('/team', 'storeUser')->middleware('module:access');
    Route::patch('/team/{id}', 'updateUser')->middleware('module:access');
    Route::patch('/modules/{key}', 'module')->middleware('module:administration');
    Route::patch('/company', 'company')->middleware('module:administration');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\ComplianceController::class)->group(function () {
    Route::get('/compliance/data', 'data');
    Route::post('/compliance/store', 'store');
    Route::post('/compliance/mark-na', 'markNotApplicable');
    Route::get('/compliance/download/{id}', 'download');
    Route::get('/compliance/history/{id}', 'history');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\TenderController::class)->group(function () {
    Route::get('/tenders/data', 'data');
    Route::get('/tenders/{id}', 'show')->whereNumber('id');
    Route::post('/tenders', 'store');
    Route::patch('/tenders/{id}', 'update')->whereNumber('id');
    Route::patch('/tenders/{id}/status', 'updateStatus')->whereNumber('id');
    Route::post('/tenders/{id}/documents', 'uploadDocument')->whereNumber('id');
    Route::get('/tenders/documents/{documentId}/download', 'downloadDocument')->whereNumber('documentId');
    Route::delete('/tenders/documents/{documentId}', 'deleteDocument')->whereNumber('documentId');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\TenderAnalysisController::class)->group(function () {
    Route::get('/tenders/{tenderId}/analysis', 'getAnalysis')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/analysis/run', 'runAnalysis')->whereNumber('tenderId');
    Route::patch('/tenders/requirements/{requirementId}', 'updateRequirement')->whereNumber('requirementId');
    Route::post('/tenders/{tenderId}/requirements', 'addRequirement')->whereNumber('tenderId');
    Route::delete('/tenders/requirements/{requirementId}', 'deleteRequirement')->whereNumber('requirementId');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\TenderTechnicalSpecController::class)->group(function () {
    Route::get('/tenders/{tenderId}/technical-specs', 'index')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/technical-specs/generate', 'generate')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/technical-specs', 'store')->whereNumber('tenderId');
    Route::patch('/tenders/technical-specs/{specId}', 'update')->whereNumber('specId');
    Route::delete('/tenders/technical-specs/{specId}', 'destroy')->whereNumber('specId');
    Route::get('/tenders/{tenderId}/technical-specs/export', 'export')->whereNumber('tenderId');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\TenderGapAnalysisController::class)->group(function () {
    Route::get('/tenders/{tenderId}/gap-analysis', 'show')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/gap-analysis/evaluate', 'evaluate')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/gap-analysis/override', 'recordOverride')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/gap-analysis/action-item', 'updateActionItem')->whereNumber('tenderId');
    Route::get('/tenders/{tenderId}/gap-analysis/export', 'exportCsv')->whereNumber('tenderId');
});

Route::middleware(['auth', 'tenant'])->controller(\App\Http\Controllers\TenderMilestoneController::class)->group(function () {
    Route::get('/tenders/{tenderId}/milestones', 'index')->whereNumber('tenderId');
    Route::post('/tenders/{tenderId}/milestones', 'store')->whereNumber('tenderId');
    Route::patch('/tenders/milestones/{milestoneId}', 'update')->whereNumber('milestoneId');
    Route::delete('/tenders/milestones/{milestoneId}', 'destroy')->whereNumber('milestoneId');
    Route::post('/tenders/{tenderId}/milestones/generate-defaults', 'generateDefaults')->whereNumber('tenderId');

    Route::post('/tenders/{tenderId}/clarifications', 'storeClarification')->whereNumber('tenderId');
    Route::patch('/tenders/clarifications/{clarificationId}', 'updateClarification')->whereNumber('clarificationId');

    Route::get('/tenders/alerts', 'getAlerts');
    Route::post('/tenders/alerts/{alertId}/read', 'markAlertRead')->whereNumber('alertId');
    Route::post('/tenders/alerts/read-all', 'markAllAlertsRead');
    Route::post('/tenders/alerts/dispatch-now', 'dispatchAlerts');
});

Route::middleware(['auth','tenant'])->group(base_path('routes/international.php'));
Route::middleware(['auth','tenant'])->group(base_path('routes/hr.php'));
Route::get('/currencies', [\App\Http\Controllers\InternationalController::class,'page'])->middleware(['auth','tenant','module:currencies','module:accounting','module:inventory']);
Route::get('/payroll', [\App\Http\Controllers\PayrollController::class, 'page'])->middleware(['auth','tenant','module:payroll']);
Route::get('/devices', [WorkspaceController::class, 'page'])->defaults('section', 'devices')->middleware(['auth', 'tenant', 'module:access']);
Route::middleware(['auth', 'tenant', 'module:reports'])->controller(ReportsController::class)->group(function () {
    Route::get('/reports', 'page');
    Route::get('/reports/export', 'export');
});
Route::middleware(['auth', 'tenant', 'module:access'])->group(function () {
    Route::post('/devices', [\App\Http\Controllers\DeviceController::class, 'enroll']);
    Route::post('/devices/{id}/revoke', [\App\Http\Controllers\DeviceController::class, 'revoke']);
});
Route::middleware(['auth','tenant','module:payroll'])->group(function () {
    Route::post('/payroll/rules', [\App\Http\Controllers\PayrollController::class, 'storeRule']);
    Route::patch('/payroll/rules/{id}/approve', [\App\Http\Controllers\PayrollController::class, 'approveRule']);
    Route::post('/payroll/employees/{employeeId}/compensation', [\App\Http\Controllers\PayrollController::class, 'storeCompensation']);
    Route::post('/payroll/runs', [\App\Http\Controllers\PayrollController::class, 'storeRun']);
    Route::patch('/payroll/runs/{id}/approve', [\App\Http\Controllers\PayrollController::class, 'approveRun']);
    Route::post('/payroll/runs/{id}/post', [\App\Http\Controllers\PayrollController::class, 'postRun']);
    Route::post('/payroll/runs/{id}/reverse', [\App\Http\Controllers\PayrollController::class, 'reverseRun']);
    Route::post('/payroll/runs/{id}/settle', [\App\Http\Controllers\PayrollController::class, 'settleRun']);
    Route::get('/payroll/runs/{id}/register', [\App\Http\Controllers\PayrollController::class, 'exportRegister']);
    Route::get('/payroll/ytd', [\App\Http\Controllers\PayrollController::class, 'exportYearToDate']);
    Route::get('/payroll/returns/monthly', [\App\Http\Controllers\PayrollController::class, 'exportMonthlyReturn']);
    Route::get('/payroll/runs/{runId}/lines/{lineId}/payslip', [\App\Http\Controllers\PayrollController::class, 'payslip']);
    Route::post('/payroll/runs/{runId}/lines/{lineId}/payslip/email', [\App\Http\Controllers\PayrollController::class, 'emailPayslip']);
});

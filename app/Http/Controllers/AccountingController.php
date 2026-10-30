<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\AccountingPurchase;
use App\Models\AccountingSetting;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\JournalBatch;
use App\Models\ProductVariant;
use App\Models\TenantModule;
use App\Services\AccountingAccess;
use App\Services\AccountingBanking;
use App\Services\AccountingCashbook;
use App\Services\AccountingLedger;
use App\Services\AccountingLedger as Ledger;
use App\Services\AccountingPurchases;
use App\Services\AccountingReports;
use App\Services\AccountingSetup;
use App\Services\InventoryAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class AccountingController
{
    public function data(Request $r): array
    {
        AccountingAccess::permit($r->user(), 'accounting.view');
        $branches = AccountingAccess::branches($r->user())->get();
        $ids = $branches->pluck('id');
        $banks = BankAccount::where(fn ($q) => $q->whereIn('branch_id', $ids)->when($r->user()->role->company_wide, fn ($q) => $q->orWhereNull('branch_id')))->get();
        $r->validate(['book' => 'nullable|in:live,training', 'before_id' => 'nullable|integer|min:1']);
        $book = $r->input('book', AccountingSetting::first()?->live_enabled ? 'live' : 'training');

        return ['setting' => AccountingSetting::first(), 'book' => $book, 'today' => Ledger::today(), 'branches' => $branches, 'warehouses' => InventoryAccess::warehouses($r->user())->get(), 'variants' => $r->user()->canDo('accounting.purchase') ? ProductVariant::where('active', true)->get(['id', 'sku', 'name']) : [], 'accounts' => ChartAccount::orderBy('code')->get(), 'mappings' => AccountMapping::get(), 'periods' => AccountingPeriod::orderByDesc('starts_on')->get(), 'journals' => JournalBatch::where('book', $book)->whereIn('branch_id', $ids)->when($r->input('before_id'), fn ($q, $id) => $q->where('id', '<', $id))->with('lines.account')->latest('id')->limit(100)->get(), 'banks' => $banks, 'statements' => BankReconciliation::whereIn('bank_account_id', $banks->pluck('id'))->where('book', $book)->with('lines.matches')->latest('id')->limit(30)->get(), 'purchases' => AccountingPurchase::whereIn('warehouse_id', InventoryAccess::warehouses($r->user())->select('warehouses.id'))->where('book', $book)->latest('id')->limit(50)->get()];
    }

    public function page(Request $r)
    {
        $company = Company::firstOrFail();

        return Inertia::render('Workspace', ['section' => 'accounting', 'accounting' => $this->data($r), 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => [...$company->only('name', 'industry', 'base_currency', 'accepted_currencies'), 'settings' => []], 'branches' => AccountingAccess::branches($r->user())->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)])]);
    }

    public function index(Request $r)
    {
        return ['data' => $this->data($r)];
    }

    public function action(Request $r, string $action, ?string $id = null)
    {
        $u = $r->user();
        $d = $r->all();
        $s = app(AccountingSetup::class);
        $b = app(AccountingBanking::class);
        $result = match ($action) {
            'setup' => $s->initialize($u, $d),'activate' => $s->activate($u, $d),'accounts' => $s->account($u, $d, $id ? ChartAccount::findOrFail($id) : null),'mappings' => $s->mapping($u, $d),'periods' => $s->period($u, $d),'lock' => $s->lockPeriod($u, AccountingPeriod::findOrFail($id)),'journals' => app(AccountingLedger::class)->manual($u, $d),'reverse' => app(AccountingLedger::class)->reverse($u, $this->journal($r, $id), $d),'cashbook' => app(AccountingCashbook::class)->post($u, $d),'purchases' => app(AccountingPurchases::class)->post($u, $d),'banks' => $b->create($u, $d),'statements' => $b->start($u, $d),'match' => $b->match($u, BankReconciliation::findOrFail($id), $d),'unmatch' => $b->unmatch($u, BankReconciliation::findOrFail($id), (int) $r->validate(['statement_line_id' => 'required|integer'])['statement_line_id']),'close' => $b->finish($u, BankReconciliation::findOrFail($id)),'cancel' => $b->finish($u, BankReconciliation::findOrFail($id), true),default => abort(404)
        };

        return response()->json(['data' => $result, 'message' => 'Accounting action saved.']);
    }

    private function journal(Request $r, string $id): JournalBatch
    {
        AccountingAccess::permit($r->user(), 'accounting.view');
        $j = JournalBatch::findOrFail($id);
        AccountingAccess::branch($r->user(), $j->branch_id);

        return $j;
    }

    public function statement(Request $r, string $id)
    {
        AccountingAccess::permit($r->user(), 'accounting.bank');
        $s = BankReconciliation::findOrFail($id);

        return ['data' => ['statement' => $s->load('lines.matches'), 'candidates' => app(AccountingBanking::class)->candidates($r->user(), $s)->get()]];
    }

    public function reports(Request $r)
    {
        return ['data' => app(AccountingReports::class)->run($r->user(), $r->all())];
    }

    public function export(Request $r)
    {
        AccountingAccess::permit($r->user(), 'accounting.export');
        $report = app(AccountingReports::class)->run($r->user(), $r->all());
        $r->validate(['report' => 'required|in:trial_balance,general_ledger']);
        $rows = $report[$r->input('report')];

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($h, array_keys($rows[0]), ',', '"', '');
            }foreach ($rows as $row) {
                fputcsv($h, array_map(function ($v) {
                    $s = (string) $v;

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $s) && ! preg_match('/^-?\d+(\.\d+)?$/D',$s) ? "'".$s : $s;
                }, $row), ',', '"', '');
            }fclose($h);
        }, 'accounting-'.$r->input('report').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

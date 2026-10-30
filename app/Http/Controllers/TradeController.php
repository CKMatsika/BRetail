<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\AccountingSetting;
use App\Models\Company;
use App\Models\CustomerCredit;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\Warehouse;
use App\Services\AccountingAccess;
use App\Services\AccountingLedger;
use App\Services\Audit;
use App\Services\InventoryAccess;
use App\Services\PurchaseOrders;
use App\Services\Suppliers;
use App\Services\TradeLedger;
use App\Services\TradePurchasing;
use App\Services\TradeSales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class TradeController
{
    private function allowed(Request $r, string $module): bool
    {
        return TenantModule::where('key', $module)->where('enabled', true)->exists() && $r->user()->canDo($module.'.view');
    }

    public function data(Request $r): array
    {
        $purchase = $this->allowed($r, 'purchasing');
        $sales = $this->allowed($r, 'sales');
        abort_unless($purchase || $sales, 403);
        $w = InventoryAccess::warehouses($r->user())->get();
        $book = AccountingAccess::book();
        $kinds = [];
        if ($purchase) {
            $kinds = [...$kinds, 'receipt', 'bill', 'supplier_payment', 'opening_payable'];
        }if ($sales) {
            $kinds = [...$kinds, 'quote', 'invoice', 'customer_receipt', 'credit_return', 'opening_receivable'];
        }$t = app(TradeLedger::class);
        $documents = TradeDocument::whereIn('warehouse_id', $w->pluck('id'))->where('book', $book)->whereIn('kind', $kinds)->latest('id')->limit(200)->get()->map(fn ($d) => [...$d->toArray(), 'original_outstanding'=>$d->currency_snapshot && in_array($d->kind,['invoice','bill'])?app(\App\Services\TradeCurrency::class)->outstandingOriginal($d):null, 'outstanding' => in_array($d->kind, ['bill', 'invoice', 'opening_payable', 'opening_receivable']) ? $t->outstanding($d) : null]);

        return ['book' => $book, 'today' => AccountingLedger::today(), 'currencies'=>TenantModule::where('key','currencies')->where('enabled',true)->exists()?\App\Models\Currency::where('active',true)->get(['code','name']):[], 'currency' => AccountingAccess::setting()->currency, 'purchasing' => $purchase, 'sales' => $sales, 'warehouses' => $w, 'suppliers' => $purchase ? Supplier::orderBy('name')->get() : [], 'customers' => $sales ? PosCustomer::orderBy('name')->get() : [], 'credits' => $sales && $r->user()->canDo('sales.configure') ? CustomerCredit::get() : [], 'orders' => $purchase ? PurchaseOrder::whereIn('warehouse_id', $w->pluck('id'))->where('book', $book)->latest('id')->limit(200)->get() : [], 'documents' => $documents, 'variants' => ProductVariant::with('product:id,name')->where('active', true)->get(['id', 'product_id', 'name', 'sku', 'selling_price']), 'foreign_accounts' => TenantModule::where('key','currencies')->where('enabled',true)->exists() && $r->user()->canDo('currencies.post') ? \App\Models\ForeignAccount::whereIn('branch_id',$w->pluck('branch_id'))->whereIn('kind',['bank','safe','mobile'])->get()->map(fn($a)=>[...$a->only('id','branch_id','name'),'currency'=>\App\Models\Currency::findOrFail($a->currency_id)->code]) : [], 'cash_accounts' => ChartAccount::whereNotIn('id',\App\Models\ForeignAccount::select('account_id'))->where('active', true)->where('is_cash', true)->get(['id', 'code', 'name']), 'report' => $t->report($r->user())];
    }

    public function page(Request $r)
    {
        if (! AccountingSetting::query()->exists()) {
            return redirect('/accounting')->with('success', 'Initialize the accounting chart and period before using sales, purchasing or back office.');
        }

        $c = Company::firstOrFail();

        return Inertia::render('Workspace', ['section' => $r->route('section'), 'trade' => $this->data($r), 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => [...$c->only('name', 'industry', 'base_currency', 'accepted_currencies'), 'settings' => []], 'branches' => AccountingAccess::branches($r->user())->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)])]);
    }

    public function index(Request $r)
    {
        return ['data' => $this->data($r)];
    }

    public function action(Request $r, string $action, ?string $id = null)
    {
        $u = $r->user();
        $d = $r->all();
        $result = match ($action) {
            'suppliers' => app(Suppliers::class)->save($u, $d, $id ? (int) $id : null),'orders' => app(PurchaseOrders::class)->create($u, $d),'approve','cancel' => app(PurchaseOrders::class)->transition($u, (int) $id, $action),'receipts' => app(TradePurchasing::class)->receive($u, (int) $id, $d),'bills' => app(TradePurchasing::class)->bill($u, (int) $id, $d),'customers' => $this->customer($r),'credits' => app(TradeLedger::class)->credit($u, $d),'quotes' => app(TradeSales::class)->quote($u, $d),'invoices' => app(TradeSales::class)->invoice($u, (int) $id, $d),'settlements' => app(TradeLedger::class)->settle($u, (int) $id, $d),'returns' => app(TradeSales::class)->creditReturn($u, (int) $id, $d),'openings' => app(TradeLedger::class)->opening($u, $d),default => abort(404)
        };

        return ['data' => $result, 'message' => 'Saved successfully.'];
    }

    private function customer(Request $r): PosCustomer
    {
        return DB::transaction(function () use ($r) {
            app(TradeLedger::class)->lock($r->user(), 'sales.quote');
            $d = $r->validate(['name' => 'required|string|max:160', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40']);
            $c = PosCustomer::create($d);
            Audit::record('sales.customer_created', (string) $c->id);

            return $c;
        });
    }

    public function print(Request $r, string $kind, string $id)
    {
        $doc = $kind === 'order' ? PurchaseOrder::findOrFail($id) : TradeDocument::findOrFail($id);
        $purchase = $kind === 'order' || in_array($doc->kind, ['receipt', 'bill', 'supplier_payment', 'opening_payable']);
        abort_unless($this->allowed($r, $purchase ? 'purchasing' : 'sales'), 403);
        AccountingAccess::branch($r->user(), Warehouse::findOrFail($doc->warehouse_id)->branch_id);

        return view('trade.document',['document' => $doc->toArray(), 'company' => Company::firstOrFail(), 'kind' => $kind === 'order' ? 'Purchase order' : str_replace('_',' ',$doc->kind)]);
    }
}

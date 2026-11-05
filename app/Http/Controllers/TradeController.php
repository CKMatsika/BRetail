<?php

namespace App\Http\Controllers;

use App\Models\AccountingSetting;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CustomerCredit;
use App\Models\ForeignAccount;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\Warehouse;
use App\Services\AccountingAccess;
use App\Services\AccountingLedger;
use App\Services\InventoryAccess;
use App\Services\PurchaseOrders;
use App\Services\Suppliers;
use App\Services\TradeCurrency;
use App\Services\TradeLedger;
use App\Services\TradePurchasing;
use App\Services\TradeSales;
use Illuminate\Http\Request;
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
        $documents = TradeDocument::current()->whereIn('warehouse_id', $w->pluck('id'))->where('book', $book)->whereIn('kind', $kinds)->latest('id')->limit(200)->get();
        $closedQuotes = TradeDocument::whereIn('parent_id', $documents->where('kind', 'quote')->pluck('id'))->whereIn('kind', ['quote', 'invoice'])->pluck('parent_id')->flip();
        $documents = $documents->map(fn ($d) => [...app(\App\Services\TradeDocuments::class)->present($d), 'quote_closed' => $d->kind === 'quote' && $closedQuotes->has($d->id), 'original_outstanding' => $d->currency_snapshot && in_array($d->kind, ['invoice', 'bill']) ? app(TradeCurrency::class)->outstandingOriginal($d) : null, 'outstanding' => in_array($d->kind, ['bill', 'invoice', 'opening_payable', 'opening_receivable']) ? $t->outstanding($d) : null]);

        return ['company_logo_url' => Company::firstOrFail()->logo_url, 'book' => $book, 'today' => AccountingLedger::today(), 'currencies' => TenantModule::where('key', 'currencies')->where('enabled', true)->exists() ? Currency::where('active', true)->get(['code', 'name']) : [], 'currency' => AccountingAccess::setting()->currency, 'purchasing' => $purchase, 'sales' => $sales, 'warehouses' => $w, 'suppliers' => $purchase ? Supplier::orderBy('name')->get() : [], 'customers' => $sales ? PosCustomer::orderBy('name')->get(['id', 'name', 'email', 'phone']) : [], 'credits' => $sales && $r->user()->canDo('sales.configure') ? CustomerCredit::get() : [], 'orders' => $purchase ? PurchaseOrder::whereIn('warehouse_id', $w->pluck('id'))->where('book', $book)->latest('id')->limit(200)->get() : [], 'documents' => $documents, 'variants' => ProductVariant::with('product:id,name,item_type')->where('active', true)->get(['id', 'product_id', 'name', 'sku', 'selling_price']), 'foreign_accounts' => TenantModule::where('key', 'currencies')->where('enabled', true)->exists() && $r->user()->canDo('currencies.post') ? ForeignAccount::whereIn('branch_id', $w->pluck('branch_id'))->whereIn('kind', ['bank', 'safe', 'mobile'])->get()->map(fn ($a) => [...$a->only('id', 'branch_id', 'name'), 'currency' => Currency::findOrFail($a->currency_id)->code]) : [], 'cash_accounts' => ChartAccount::whereNotIn('id', ForeignAccount::select('account_id'))->where('active', true)->where('is_cash', true)->get(['id', 'code', 'name']), 'accounts' => ChartAccount::where('active', true)->where('is_cash', false)->orderBy('code')->get(['id', 'code', 'name', 'type', 'report_group']), 'report' => $t->report($r->user())];
    }

    public function page(Request $r)
    {
        if (! AccountingSetting::query()->exists()) {
            return redirect('/accounting')->with('success', 'Initialize the accounting chart and period before using sales, purchasing or back office.');
        }

        $c = Company::firstOrFail();

        return Inertia::render('Workspace', ['section' => $r->route('section'), 'trade' => $this->data($r), 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => [...$c->only('name', 'industry', 'base_currency', 'accepted_currencies', 'logo_url'), 'settings' => []], 'branches' => AccountingAccess::branches($r->user())->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)])]);
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
            'suppliers' => app(Suppliers::class)->save($u, $d, $id ? (int) $id : null),'orders' => app(PurchaseOrders::class)->create($u, $d),'approve','cancel' => app(PurchaseOrders::class)->transition($u, (int) $id, $action),'receipts' => app(TradePurchasing::class)->receive($u, (int) $id, $d),'bills' => app(TradePurchasing::class)->bill($u, (int) $id, $d),'direct-bills' => app(TradePurchasing::class)->directBill($u, $d),'customers' => app(\App\Services\Customers::class)->save($u, $d, $id ? (int) $id : null),'credits' => app(TradeLedger::class)->credit($u, $d),'quote-preview' => app(TradeSales::class)->quote($u, $d, $id ? (int) $id : null, true),'quotes' => app(TradeSales::class)->quote($u, $d, $id ? (int) $id : null),'invoice-preview' => app(\App\Services\TradeInvoiceEdits::class)->amend($u, (int) $id, $d, true),'invoice-edits' => app(\App\Services\TradeInvoiceEdits::class)->amend($u, (int) $id, $d),'invoice-details' => app(\App\Services\TradeInvoiceEdits::class)->details($u, (int) $id, $d),'invoices' => app(TradeSales::class)->invoice($u, (int) $id, $d),'settlements' => app(TradeLedger::class)->settle($u, (int) $id, $d),'returns' => app(TradeSales::class)->creditReturn($u, (int) $id, $d),'openings' => app(TradeLedger::class)->opening($u, $d),default => abort(404)
        };

        return ['data' => $result instanceof TradeDocument ? app(\App\Services\TradeDocuments::class)->present($result) : $result, 'message' => $action === 'quote-preview' ? 'Preview calculated.' : 'Saved successfully.'];
    }

    public function customer(Request $r, string $id)
    {
        abort_unless($this->allowed($r, 'sales'), 403);

        return ['data' => PosCustomer::findOrFail($id)->only('id', 'name', 'email', 'phone')];
    }

    public function updateCustomer(Request $r, string $id)
    {
        return ['data' => app(\App\Services\Customers::class)->save($r->user(), $r->all(), (int) $id), 'message' => 'Customer updated.'];
    }

    public function deleteCustomer(Request $r, string $id)
    {
        app(\App\Services\Customers::class)->delete($r->user(), (int) $id);

        return response()->json(['message' => 'Customer deleted.']);
    }

    public function history(Request $r, string $id)
    {
        abort_unless($this->allowed($r, 'sales'), 403);
        $doc = TradeDocument::whereIn('kind', ['quote', 'invoice'])->findOrFail($id);
        $versions = $doc->versions();
        foreach ($versions as $version) {
            InventoryAccess::warehouse($r->user(), $version->warehouse_id);
        }

        return ['data' => array_map(fn ($version) => app(\App\Services\TradeDocuments::class)->present($version), $versions), 'details' => \App\Models\TradeDocumentDetail::where('document_id', $doc->id)->latest('id')->get(['reference', 'notes', 'created_at'])];
    }

    public function print(Request $r, string $kind, string $id)
    {
        $doc = $kind === 'order' ? PurchaseOrder::findOrFail($id) : TradeDocument::findOrFail($id);
        $purchase = $kind === 'order' || in_array($doc->kind, ['receipt', 'bill', 'supplier_payment', 'opening_payable']);
        abort_unless($this->allowed($r, $purchase ? 'purchasing' : 'sales'), 403);
        AccountingAccess::branch($r->user(), Warehouse::findOrFail($doc->warehouse_id)->branch_id);

        return view('trade.document', ['document' => $doc instanceof TradeDocument ? app(\App\Services\TradeDocuments::class)->present($doc) : $doc->toArray(), 'company' => Company::firstOrFail(), 'kind' => $kind === 'order' ? 'Purchase order' : str_replace('_', ' ', $doc->kind)]);
    }
}

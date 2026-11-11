<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\TenantModule;
use App\Services\Catalog;
use App\Services\CatalogCsv;
use App\Services\InventoryAccess;
use App\Services\StockCounting;
use App\Services\StockLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController
{
    public function data(Request $r): array
    {
        InventoryAccess::permit($r->user(), 'inventory.view');
        $filters = $r->validate(['before_id' => 'nullable|integer|min:1']);
        $warehouses = InventoryAccess::warehouses($r->user())->get();
        $warehouseIds = $warehouses->pluck('id');
        $recentCountIds = StockCount::whereIn('warehouse_id', $warehouseIds)->latest('id')->limit(30)->pluck('id');

        $settings = Company::firstOrFail()->settings;
        $taxRates = $settings['tax_rates'] ?? [];
        return ['products' => Product::with('variants')->orderBy('name')->get(), 'taxRates' => $taxRates, 'defaultTax' => ['name' => $settings['tax_label'] ?? 'Tax', 'rate' => $settings['tax_rate'] ?? '0'], 'classifications' => Classification::orderBy('name')->get(), 'warehouses' => $warehouses, 'balances' => StockBalance::whereIn('warehouse_id', $warehouseIds)->get(), 'movements' => StockMovement::whereIn('warehouse_id', $warehouseIds)->when($filters['before_id'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->with('variant', 'document')->latest('id')->limit(100)->get(), 'counts' => StockCount::whereIn('warehouse_id', $warehouseIds)->where(fn ($q) => $q->whereIn('id', $recentCountIds)->orWhere('status', 'draft'))->with('lines.variant')->latest('id')->get()];
    }

    public function page(Request $r)
    {
        $data = $this->data($r);
        $company = Company::firstOrFail();

        return Inertia::render('Workspace', [...$data, 'section' => 'inventory', 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => [...$company->only('name', 'industry', 'base_currency', 'accepted_currencies', 'logo_url'), 'settings' => []], 'branches' => Branch::whereIn('id', collect($data['warehouses'])->pluck('branch_id'))->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)])]);
    }

    private function saved(Request $r, mixed $data, string $message, int $status = 200)
    {
        return $r->is('api/*') || $r->expectsJson() ? response()->json(['data' => $data, 'message' => $message], $status) : back()->with('success', $message);
    }

    public function index(Request $r)
    {
        return response()->json(['data' => $this->data($r)]);
    }

    public function product(Request $r, Catalog $c, ?string $id = null)
    {
        $product = $id ? Product::findOrFail($id) : null;

        return $this->saved($r, $c->product($r->user(), $r->all(), $product), 'Product saved.', $id ? 200 : 201);
    }

    public function variant(Request $r, Catalog $c, string $product, ?string $id = null)
    {
        $p = Product::findOrFail($product);
        $v = $id ? ProductVariant::findOrFail($id) : null;

        return $this->saved($r, $c->variant($r->user(), $p, $r->all(), $v), 'Variant saved.', $id ? 200 : 201);
    }

    public function classification(Request $r, Catalog $c)
    {
        return $this->saved($r, $c->classification($r->user(), $r->all()), 'Classification created.', 201);
    }

    public function warehouse(Request $r, Catalog $c)
    {
        return $this->saved($r, $c->warehouse($r->user(), $r->all()), 'Warehouse created.', 201);
    }

    public function movement(Request $r, StockLedger $ledger)
    {
        return $this->saved($r, $ledger->post($r->user(), $r->all()), 'Stock document posted.');
    }

    public function count(Request $r, StockCounting $service)
    {
        return $this->saved($r, $service->start($r->user(), $r->all()), 'Count snapshot created.', 201);
    }

    public function finishCount(Request $r, StockCounting $service, string $id)
    {
        return $this->saved($r, $service->finish($r->user(), StockCount::findOrFail($id), $r->all()), 'Stock count posted.');
    }

    public function cancelCount(Request $r, StockCounting $service, string $id)
    {
        $service->cancel($r->user(), StockCount::findOrFail($id));

        return $this->saved($r, null, 'Stock count cancelled.');
    }

    public function reconcile(Request $r, StockLedger $ledger, string $id)
    {
        $result = $ledger->reconcile($r->user(), (int) $id);

        return $this->saved($r, $result, $result['balanced'] ? 'Ledger and balances reconcile.' : 'Reconciliation differences detected.');
    }

    public function import(Request $r, CatalogCsv $csv)
    {
        InventoryAccess::permit($r->user(), 'inventory.import');
        $r->validate(['file' => 'required|file|max:2048|mimes:csv,txt']);
        $count = $csv->import($r->user(), $r->file('file'));

        return $this->saved($r, ['imported' => $count], "Imported $count variants atomically.");
    }

    public function export(Request $r, CatalogCsv $csv)
    {
        return $csv->export($r->user());
    }

    public function template(Request $r, CatalogCsv $csv)
    {
        return $csv->export($r->user(), true);
    }
}

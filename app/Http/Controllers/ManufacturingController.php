<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Services\InventoryAccess;
use App\Services\Manufacturing;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ManufacturingController
{
    public function page(Request $r)
    {
        InventoryAccess::permit($r->user(), 'manufacturing.view');
        $warehouses = InventoryAccess::warehouses($r->user())->get();
        $company = Company::firstOrFail();
        return Inertia::render('Workspace', [
            'section' => 'manufacturing',
            'manufacturing' => [
                'recipes' => ProductionRecipe::with(['output.product', 'lines.variant.product'])->orderBy('name')->get(),
                'orders' => ProductionOrder::with(['recipe.output.product', 'lines.variant.product'])->whereIn('warehouse_id', $warehouses->pluck('id'))->latest('id')->limit(100)->get(),
                'variants' => ProductVariant::with('product:id,name,item_type')->where('active', true)->whereHas('product', fn ($q) => $q->where('active', true)->where('item_type', 'stock'))->orderBy('sku')->get(['id','product_id','name','sku','cost_price']),
                'warehouses' => $warehouses,
                'balances' => StockBalance::whereIn('warehouse_id', $warehouses->pluck('id'))->get(['variant_id','warehouse_id','quantity','average_cost']),
                'canManage' => $r->user()->canDo('manufacturing.manage'),
                'canProduce' => $r->user()->canDo('manufacturing.produce'),
                'baseCurrency' => $company->base_currency,
            ],
            'user' => $r->user()->only('id','name','email'), 'role' => $r->user()->role->name,
            'permissions' => $r->user()->role->permissions,
            'company' => [...$company->only('name','industry','base_currency','accepted_currencies','logo_url'), 'settings' => []],
            'branches' => Branch::whereIn('id', $warehouses->pluck('branch_id'))->with('warehouses')->get(),
            'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)]),
        ]);
    }

    public function recipe(Request $r, Manufacturing $service, ?string $id = null)
    {
        InventoryAccess::permit($r->user(), 'manufacturing.manage');
        $recipe = $id ? ProductionRecipe::with('lines')->findOrFail($id) : null;
        return back()->with('success', 'Production recipe saved.')->with('manufacturing_saved', $service->saveRecipe($r->user(), $r->all(), $recipe));
    }

    public function order(Request $r, Manufacturing $service)
    {
        InventoryAccess::permit($r->user(), 'manufacturing.manage');
        $order = $service->createOrder($r->user(), $r->all());
        return back()->with('success', "Production order {$order->reference} created.");
    }

    public function complete(Request $r, Manufacturing $service, string $id)
    {
        InventoryAccess::permit($r->user(), 'manufacturing.produce');
        $service->complete($r->user(), ProductionOrder::with('recipe.lines')->findOrFail($id));
        return back()->with('success', 'Production completed and inventory updated.');
    }

    public function cancel(Request $r, Manufacturing $service, string $id)
    {
        InventoryAccess::permit($r->user(), 'manufacturing.manage');
        $service->cancel($r->user(), ProductionOrder::findOrFail($id));
        return back()->with('success', 'Production order cancelled.');
    }
}

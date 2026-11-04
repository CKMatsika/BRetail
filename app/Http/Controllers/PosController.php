<?php

namespace App\Http\Controllers;

use App\Models\AccountingSetting;
use App\Models\Branch;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ForeignAccount;
use App\Models\PosCustomer;
use App\Models\PosHold;
use App\Models\PosReturn;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\InventoryAccess;
use App\Services\PosAccess;
use App\Services\PosAccountingGate;
use App\Services\PosCart;
use App\Services\PosForeign;
use App\Services\PosHolds;
use App\Services\PosReports;
use App\Services\PosReturns;
use App\Services\PosSales;
use App\Services\PosShifts;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PosController
{
    private function data(Request $r): array
    {
        $u = $r->user();
        InventoryAccess::permit($u, 'pos.view');
        $filters = $r->validate(['date' => 'nullable|date_format:Y-m-d', 'receipt' => 'nullable|string|max:100']);
        $warehouses = InventoryAccess::warehouses($u)->get();
        $ids = $warehouses->pluck('id');
        $sessionQuery = PosSession::whereIn('warehouse_id', $ids)->when(! $u->canDo('pos.manage'), fn ($q) => $q->where('user_id', $u->id));
        $recent = (clone $sessionQuery)->latest('id')->limit(30)->pluck('id');
        $sessions = $sessionQuery->where(fn ($q) => $q->whereIn('id', $recent)->orWhere('status', 'open'))->latest('id')->get();
        $names = User::where('tenant_id', $u->tenant_id)->whereIn('id', $sessions->pluck('user_id')->merge($sessions->pluck('closed_by'))->filter())->pluck('name', 'id');
        $own = PosSession::where('user_id', $u->id)->where('status', 'open')->first();
        $loyaltyAvailable = TenantModule::where('key', 'loyalty')->where('enabled', true)->exists()
            && app(\App\Services\LoyaltyService::class)->program()->active;

        return ['pos' => [
            'training_available' => app(PosAccountingGate::class)->training(),
            'live_available' => AccountingSetting::where('live_enabled', true)->exists() && TenantModule::where('key', 'accounting')->where('enabled', true)->exists(),
            'foreign_safes' => TenantModule::where('key', 'currencies')->where('enabled', true)->exists() ? ForeignAccount::whereIn('branch_id', $warehouses->pluck('branch_id'))->whereIn('kind', ['safe', 'bank', 'mobile'])->get()->map(fn ($a) => [...$a->toArray(), 'currency' => Currency::findOrFail($a->currency_id)->code]) : [], 'currency' => Company::firstOrFail()->base_currency, 'discount_limit' => config('pos.cashier_discount_limit'),
            'current_user_id' => $u->id,
            'daily' => app(PosReports::class)->daily($u, $filters['date'] ?? null),
            'warehouses' => $warehouses, 'departments' => Classification::where('level', 'department')->orderBy('name')->get(['id', 'name']),
            'variants' => ProductVariant::with('product')->where('active', true)->whereHas('product', fn ($q) => $q->where('active', true))->orderBy('name')->get()->map(fn ($v) => [...$v->only('id', 'name', 'sku', 'barcode', 'selling_price', 'attributes'), 'item_type' => $v->product->item_type, 'product_name' => $v->product->name, 'department_id' => $v->product->department_id, 'brand' => $v->product->brand]),
            'stock' => StockBalance::whereIn('warehouse_id', $ids)->get(['warehouse_id', 'variant_id', 'quantity']),
            'loyalty_available' => $loyaltyAvailable,
            'customers' => PosCustomer::orderBy('name')->get(['id', 'name', 'email', 'phone', 'loyalty_number', 'loyalty_consent_at', 'loyalty_opted_out_at'])->map(fn ($customer) => [...$customer->only('id', 'name', 'email', 'phone', 'loyalty_number'), 'loyalty_enrolled' => $loyaltyAvailable && $customer->loyalty_consent_at !== null && $customer->loyalty_opted_out_at === null]),
            'holds' => PosHold::whereIn('warehouse_id', $ids)->when(! $u->canDo('pos.manage'), fn ($q) => $q->where('user_id', $u->id))->where('status', 'held')->latest('id')->get(),
            'sessions' => $sessions->map(fn ($s) => [...$s->toArray(), 'cashier_name' => $names[$s->user_id] ?? 'Cashier', 'closed_by_name' => $names[$s->closed_by] ?? null, 'summary' => app(PosShifts::class)->summary($s)]),
            'own_session' => $own && $ids->contains($own->warehouse_id) ? [...$own->toArray(), 'summary' => app(PosShifts::class)->summary($own)] : null,
            'sales' => app(PosAccess::class)->sales($u)->when($filters['receipt'] ?? null, fn ($q, $search) => $q->whereRaw('LOWER(receipt_number) LIKE ?', ['%'.mb_strtolower($search).'%']))->latest('id')->limit(50)->get(['id', 'receipt_number', 'user_id', 'warehouse_id', 'session_id', 'customer_name', 'cashier_name', 'currency', 'total', 'tax', 'mode', 'created_at']),
        ]];
    }

    public function page(Request $r)
    {
        $data = $this->data($r);
        $company = Company::firstOrFail();

        return Inertia::render('Workspace', [...$data, 'section' => 'pos', 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => [...$company->only('name', 'industry', 'base_currency', 'accepted_currencies'), 'settings' => []], 'branches' => Branch::whereIn('id', collect($data['pos']['warehouses'])->pluck('branch_id'))->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config('modules.'.$m->key)])]);
    }

    public function index(Request $r)
    {
        return response()->json(['data' => $this->data($r)['pos']]);
    }

    private function saved(Request $r, mixed $data, string $message, int $status = 200)
    {
        return $r->expectsJson() || $r->is('api/*') ? response()->json(['data' => $data, 'message' => $message], $status) : back()->with('success', $message);
    }

    public function quote(Request $r, PosCart $c)
    {
        return response()->json(['data' => $c->quote($r->user(), $r->all())]);
    }

    public function checkout(Request $r, PosSales $s)
    {
        if ($r->has('foreign_tenders')) {
            $sale = app(PosForeign::class)->checkout($r->user(), $r->all());

            return $this->saved($r, app(PosAccess::class)->detail($sale), 'Sale completed.', 201);
        }

        return $this->saved($r, $s->checkout($r->user(), $r->all()), 'Sale completed.', 201);
    }

    public function sale(Request $r, string $id)
    {
        return response()->json(['data' => app(PosAccess::class)->detail(app(PosAccess::class)->sale($r->user(), (int) $id))]);
    }

    public function open(Request $r, PosShifts $s)
    {
        return $this->saved($r, $s->open($r->user(), $r->all()), 'Cashier shift opened.', 201);
    }

    public function close(Request $r, PosShifts $s, string $id)
    {
        return $this->saved($r, $s->close($r->user(), (int) $id, $r->all()), 'Cashier shift closed.');
    }

    public function hold(Request $r, PosHolds $s)
    {
        return $this->saved($r, $s->create($r->user(), $r->all()), 'Sale held.', 201);
    }

    public function cancelHold(Request $r, PosHolds $s, string $id)
    {
        $s->cancel($r->user(), (int) $id);

        return $this->saved($r, null, 'Held cart cancelled.');
    }

    public function customer(Request $r, PosHolds $s)
    {
        return $this->saved($r, $s->customer($r->user(), $r->all()), 'Customer created.', 201);
    }

    public function customerLoyalty(Request $request, string $id)
    {
        InventoryAccess::permit($request->user(), 'pos.sell');
        abort_unless(TenantModule::where('key', 'loyalty')->where('enabled', true)->exists(), 404);
        $customer = PosCustomer::findOrFail($id);
        $service = app(\App\Services\LoyaltyService::class);
        $program = $service->program();
        $enrolled = $service->enrolled($customer) && $program->active;
        $points = $enrolled ? $service->balance($customer) : '0.000';
        $value = \Brick\Math\BigDecimal::of($points)->multipliedBy($program->value_per_point)->toScale(2, \Brick\Math\RoundingMode::HalfUp);

        return response()->json(['data' => ['enrolled' => $enrolled, 'points' => $points, 'value' => (string) $value, 'currency' => Company::firstOrFail()->base_currency]]);
    }

    public function returnQuote(Request $r, PosReturns $s, string $id)
    {
        $quote = $s->quote($r->user(), app(PosAccess::class)->sale($r->user(), (int) $id), $r->all());
        $quote['items'] = array_map(fn ($i) => collect($i)->except('cost_value')->all(), $quote['items']);

        return response()->json(['data' => $quote]);
    }

    public function refund(Request $r, PosReturns $s, string $id)
    {
        if ($r->has('foreign_refunds')) {
            return $this->saved($r, app(PosForeign::class)->refund($r->user(), app(PosAccess::class)->sale($r->user(), (int) $id), $r->all()), 'Return completed.', 201);
        }

        return $this->saved($r, $s->post($r->user(), app(PosAccess::class)->sale($r->user(), (int) $id), $r->all()), 'Return completed.', 201);
    }

    public function receipt(Request $r, string $id)
    {
        return response()->view('pos.receipt', ['sale' => app(PosAccess::class)->detail(app(PosAccess::class)->sale($r->user(), (int) $id)), 'return' => null])->header('Cache-Control', 'private, no-store');
    }

    public function returnReceipt(Request $r, string $id)
    {
        $return = PosReturn::with('items', 'payments')->findOrFail($id);
        $sale = app(PosAccess::class)->sale($r->user(), $return->sale_id);

        return response()->view('pos.receipt', ['sale' => app(PosAccess::class)->detail($sale), 'return' => $return->toArray()])->header('Cache-Control', 'private, no-store');
    }

    public function currencyDrawer(Request $r)
    {
        return ['data' => app(PosForeign::class)->openDrawer($r->user(), $r->all())];
    }

    public function countCurrencyDrawer(Request $r, string $id)
    {
        return ['data' => app(PosForeign::class)->closeDrawer($r->user(), (int) $id, $r->all())];
    }
}

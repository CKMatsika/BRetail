<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PurchaseOrders
{
    private function lock(User $u, string $permission): void
    {
        AccountingAccess::lock($u, $permission);
        abort_unless(TenantModule::where('key', 'purchasing')->where('enabled', true)->exists(), 403);
    }

    public function create(User $u, array $input): PurchaseOrder
    {
        $d = Validator::make($input, [
            'request_id' => 'required|uuid', 'supplier_id' => 'required|integer', 'warehouse_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d', 'expected_on' => 'nullable|date_format:Y-m-d|after_or_equal:date',
            'notes' => 'nullable|string|max:2000', 'currency' => 'nullable|string|size:3', 'items' => 'required|array|min:1|max:500',
            'items.*.variant_id' => 'required|integer|distinct',
            'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'items.*.unit_cost' => ['required', 'string', Catalog::MONEY],
        ])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'purchasing.create');
            InventoryAccess::warehouse($u, $d['warehouse_id']);
            $hash = PosMath::hash($d);
            if ($old = PurchaseOrder::where('request_id', $d['request_id'])->first()) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Purchase order request conflict.']);
                }

                return $old;
            }
            $supplier = Supplier::findOrFail($d['supplier_id']);
            if (! $supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier is inactive.']);
            }
            $base = Company::firstOrFail()->base_currency;
            $code = $d['currency'] ?? $base;
            $rate = null;
            if ($code !== $base) {
                abort_unless(TenantModule::where('key', 'currencies')->where('enabled', true)->exists(), 403);
            }
            $items = [];
            $originalTotal = BigDecimal::zero();
            foreach ($d['items'] as $item) {
                $v = ProductVariant::with('product')->findOrFail($item['variant_id']);
                if (! $v->active || ! $v->product->active || ! BigDecimal::of($item['quantity'])->isPositive()) {
                    throw ValidationException::withMessages(['items' => 'Choose active products and positive quantities.']);
                }
                $quantity = BigDecimal::of($item['quantity'])->toScale(3);
                if ($v->product->item_type === 'service') {
                    throw ValidationException::withMessages(['items' => 'Goods purchase orders require physical stock items. Services cannot be received into stock.']);
                }
                $originalCost = BigDecimal::of($item['unit_cost'])->toScale(4);
                $originalTotal = $originalTotal->plus($quantity->multipliedBy($originalCost)->toScale(4, RoundingMode::HalfUp));
                $items[] = [...$item, 'quantity' => (string) $quantity, 'unit_cost' => (string) $originalCost, 'sku' => $v->sku, 'description' => $v->product->name.' / '.$v->name];
            }
            $snapshot = null;
            if ($code !== $base) {
                $snapshot = app(CurrencyRates::class)->snapshot($code, $d['date'], (string) $originalTotal);
                $rate = BigDecimal::of($snapshot['exchange_rate']);
                foreach ($items as &$item) {
                    $item['original_unit_cost'] = $item['unit_cost'];
                    $item['unit_cost'] = (string) BigDecimal::of($item['unit_cost'])->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp);
                }
                unset($item);
                $snapshot['base_amount'] = (string) collect($items)->reduce(fn ($sum, $item) => $sum->plus(BigDecimal::of($item['quantity'])->multipliedBy($item['unit_cost'])->toScale(4, RoundingMode::HalfUp)), BigDecimal::zero())->toScale(4);
            }
            $number = 'PO-'.str_pad((string) ((PurchaseOrder::max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
            $order = PurchaseOrder::create([...collect($d)->except('currency')->all(), 'items' => $items, 'currency_snapshot' => $snapshot, 'number' => $number, 'book' => AccountingAccess::book(), 'supplier_name' => $supplier->name, 'actor_id' => $u->id, 'payload_hash' => $hash]);
            Audit::record('purchasing.order_created', (string) $order->id);

            return $order;
        }, 3);
    }

    public function transition(User $u, int $id, string $action): PurchaseOrder
    {
        return DB::transaction(function () use ($u, $id, $action) {
            abort_unless(in_array($action, ['approve', 'cancel'], true), 404);
            $this->lock($u, $action === 'approve' ? 'purchasing.approve' : 'purchasing.create');
            $order = PurchaseOrder::findOrFail($id);
            InventoryAccess::warehouse($u, $order->warehouse_id);
            $target = $action === 'approve' ? 'approved' : 'cancelled';
            if ($order->status === $target) {
                return $order;
            }
            if (($action === 'approve' && $order->status !== 'draft') || ($action === 'cancel' && ! in_array($order->status, ['draft', 'approved', 'partially_received'], true))) {
                throw ValidationException::withMessages(['order' => 'Only draft orders can be approved; only unfinished orders can be cancelled.']);
            }
            if ($order->book !== AccountingAccess::book()) {
                throw ValidationException::withMessages(['book' => 'Order belongs to a different operating book.']);
            }
            if ($action === 'approve' && ! Supplier::findOrFail($order->supplier_id)->active) {
                throw ValidationException::withMessages(['supplier' => 'Supplier is inactive.']);
            }
            $order->update(['status' => $target, ...($action === 'approve' ? ['approved_by' => $u->id, 'approved_at' => now()] : [])]);
            Audit::record('purchasing.order_'.$target,(string) $order->id);

            return $order;
        }, 3);
    }
}

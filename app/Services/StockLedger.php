<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StockLedger
{
    public function post(User $user, array $input, bool $count = false): StockDocument
    {
        return $this->write($user, $input, $count ? 'count' : 'inventory');
    }

    public function sale(User $user, array $input): StockDocument
    {
        return $this->write($user, $input, 'sale');
    }

    public function customerReturn(User $user, array $input): StockDocument
    {
        return $this->write($user, $input, 'return');
    }

    public function purchase(User $user, array $input): StockDocument
    {
        return $this->write($user, $input, 'purchase');
    }

    public function trade(User $user, array $input): StockDocument
    {
        $operation = match ($input['type'] ?? '') {
            'goods_receipt' => 'trade_receive', 'credit_sale' => 'trade_sale', 'credit_return' => 'trade_return', default => abort(422),
        };

        return $this->write($user, $input, $operation);
    }

    public function transfer(User $user, array $input): StockDocument
    {
        return $this->write($user, $input, match ($input['type'] ?? '') {
            'transfer_dispatch' => 'transfer_dispatch', 'transfer_receive' => 'transfer_receive', default => abort(422),
        });
    }

    private function write(User $user, array $input, string $operation): StockDocument
    {
        InventoryAccess::permit($user, match ($operation) {
            'transfer_dispatch' => 'transfers.dispatch', 'transfer_receive' => 'transfers.receive', 'count' => 'inventory.count','sale' => 'pos.sell','return' => 'pos.refund','purchase' => 'accounting.purchase','trade_receive' => 'purchasing.receive','trade_sale' => 'sales.invoice','trade_return' => 'sales.return',default => 'inventory.adjust'
        });
        $types = match ($operation) {
            'transfer_dispatch' => ['transfer_dispatch'], 'transfer_receive' => ['transfer_receive'], 'count' => ['count'],'sale' => ['sale'],'return' => ['customer_return'],'purchase' => ['purchase'],'trade_receive' => ['goods_receipt'],'trade_sale' => ['credit_sale'],'trade_return' => ['credit_return'],default => ['opening', 'adjustment']
        };
        if (in_array($operation, ['sale', 'return'], true)) {
            app(PosAccountingGate::class)->mode();
        }
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'warehouse_id' => 'required|integer', 'type' => ['required', Rule::in($types)], 'reference' => 'required|string|max:160', 'reason' => 'required|string|max:2000', 'lines' => 'required|array|min:1|max:2000', 'lines.*.variant_id' => 'required|integer|distinct', 'lines.*.quantity' => ['required', 'string', 'regex:/^-?\d{1,9}(\.\d{1,3})?$/'], 'lines.*.unit_cost' => ['nullable', 'string', Catalog::MONEY], 'lines.*.value_override' => in_array($operation, ['return', 'trade_return', 'transfer_receive'], true) ? ['required', 'string', 'regex:/^\d{1,20}(\.\d{1,4})?$/'] : ['prohibited']])->validate();
        usort($d['lines'], fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']);
        // Normalize decimal representations before hashing a retry payload.
        $canonical = ['warehouse_id' => (int) $d['warehouse_id'], 'type' => $d['type'], 'reference' => $d['reference'], 'reason' => $d['reason'], 'lines' => array_map(fn ($l) => ['variant_id' => (int) $l['variant_id'], 'quantity' => (string) BigDecimal::of($l['quantity'])->toScale(3), 'unit_cost' => isset($l['unit_cost']) ? (string) BigDecimal::of($l['unit_cost'])->toScale(4) : null], $d['lines'])];
        if (in_array($operation, ['return', 'trade_return', 'transfer_receive'], true)) {
            foreach ($canonical['lines'] as $i => &$line) {
                $line['value_override'] = (string) BigDecimal::of($d['lines'][$i]['value_override'])->toScale(4);
            }
        }
        unset($line);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $d, $canonical, $hash, $operation) {
            app(Catalog::class)->lock();
            if (in_array($operation, ['sale', 'return'], true)) {
                abort_unless(TenantModule::where('key', 'pos')->where('enabled', true)->exists(), 403, 'Point of sale is disabled.');
            }
            abort_unless(TenantModule::where('key', 'inventory')->where('enabled', true)->exists(), 403, 'Inventory is disabled.');
            InventoryAccess::warehouse($user, $d['warehouse_id']);
            $existing = StockDocument::where('request_id', $d['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'This request ID was already used with different data.']);
                }

                return $existing->load('movements');
            }
            $document = StockDocument::create(['request_id' => $d['request_id'], 'payload_hash' => $hash, 'type' => $d['type'], 'warehouse_id' => $d['warehouse_id'], 'actor_id' => $user->id, 'reference' => $d['reference'], 'reason' => $d['reason'], 'created_at' => now()]);
            foreach ($canonical['lines'] as $line) {
                $variant = ProductVariant::with('product')->findOrFail($line['variant_id']);
                abort_unless(in_array($operation, ['return', 'trade_return', 'transfer_receive'], true) || ($variant->active && $variant->product->active), 422, 'Inactive products cannot receive stock operations.');
                $quantity = BigDecimal::of($line['quantity']);
                if ((in_array($operation, ['sale', 'trade_sale', 'transfer_dispatch'], true) && ! $quantity->isNegative()) || (in_array($operation, ['return', 'purchase', 'trade_receive', 'trade_return', 'transfer_receive'], true) && ! $quantity->isPositive())) {
                    throw ValidationException::withMessages(['lines' => 'Invalid stock direction for this POS operation.']);
                }
                if ($quantity->isZero()) {
                    throw ValidationException::withMessages(['lines' => 'Movement quantities must be nonzero.']);
                }
                $balance = StockBalance::firstOrCreate(['variant_id' => $variant->id, 'warehouse_id' => $d['warehouse_id']], ['quantity' => '0.000', 'value' => '0.0000', 'average_cost' => '0.0000']);
                $oldQty = BigDecimal::of($balance->quantity);
                $oldValue = BigDecimal::of($balance->value);
                $newQty = $oldQty->plus($quantity);
                if ($newQty->isNegative()) {
                    throw ValidationException::withMessages(['lines' => "Insufficient stock for {$variant->sku}."]);
                }
                if ($d['type'] === 'opening' && ($quantity->isNegative() || StockMovement::where('warehouse_id', $d['warehouse_id'])->where('variant_id', $variant->id)->exists())) {
                    throw ValidationException::withMessages(['lines' => 'Opening stock must be positive and precede all other movements.']);
                }
                if ($quantity->isPositive()) {
                    if ($line['unit_cost'] === null) {
                        throw ValidationException::withMessages(['lines' => 'Positive stock requires a unit cost.']);
                    }
                    $cost = BigDecimal::of($line['unit_cost']);
                    $value = $quantity->multipliedBy($cost)->toScale(4, RoundingMode::HalfUp);
                    if (in_array($operation, ['return', 'trade_return', 'transfer_receive'], true)) {
                        $value = BigDecimal::of($line['value_override']);
                    }
                } else {
                    $cost = $oldValue->dividedBy($oldQty, 4, RoundingMode::HalfUp);
                    $value = $newQty->isZero() ? $oldValue->negated() : $oldValue->multipliedBy($quantity)->dividedBy($oldQty, 4, RoundingMode::HalfUp);
                }
                $newValue = $oldValue->plus($value)->toScale(4);
                $average = $newQty->isZero() ? BigDecimal::of('0.0000') : $newValue->dividedBy($newQty, 4, RoundingMode::HalfUp);
                StockMovement::create(['document_id' => $document->id, 'variant_id' => $variant->id, 'warehouse_id' => $d['warehouse_id'], 'quantity' => (string) $quantity, 'unit_cost' => (string) $cost, 'value' => (string) $value, 'created_at' => now()]);
                $balance->update(['quantity' => (string) $newQty, 'value' => (string) $newValue, 'average_cost' => (string) $average]);
            }
            app(AccountingBridge::class)->stock($user, $document);
            Audit::record('stock.posted', (string) $document->id, ['type' => $d['type'], 'reference' => $d['reference'], 'warehouse_id' => $d['warehouse_id']]);

            return $document->load('movements');
        }, 3);
    }

    public function reconcile(User $user, int $warehouseId): array
    {
        InventoryAccess::permit($user, 'inventory.view');
        InventoryAccess::warehouse($user, $warehouseId);

        return DB::transaction(function () use ($warehouseId) {
            app(Catalog::class)->lock();
            $sums = [];
            foreach (StockMovement::where('warehouse_id', $warehouseId)->lazyById(500) as $m) {
                $sums[$m->variant_id] ??= ['quantity' => BigDecimal::zero(), 'value' => BigDecimal::zero()];
                foreach (['quantity', 'value'] as $k) {
                    $sums[$m->variant_id][$k] = $sums[$m->variant_id][$k]->plus($m->$k);
                }
            }
            $balances = StockBalance::where('warehouse_id', $warehouseId)->get()->keyBy('variant_id');
            $issues = [];
            foreach (array_unique([...array_keys($sums), ...$balances->keys()->all()]) as $id) {
                $b = $balances->get($id);
                $q = $sums[$id]['quantity'] ?? BigDecimal::zero();
                $v = $sums[$id]['value'] ?? BigDecimal::zero();
                $avg = $q->isZero() ? BigDecimal::zero() : $v->dividedBy($q, 4, RoundingMode::HalfUp);
                if (! $b || ! $q->isEqualTo($b->quantity) || ! $v->isEqualTo($b->value) || ! $avg->isEqualTo($b->average_cost)) {
                    $issues[] = ['variant_id' => $id, 'ledger_quantity' => (string) $q->toScale(3), 'ledger_value' => (string) $v->toScale(4)];
                }
            }

            return ['balanced' => count($issues) === 0, 'issues' => $issues];
        });
    }
}

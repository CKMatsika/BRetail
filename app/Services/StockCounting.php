<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockCounting
{
    public function start(User $user, array $input): StockCount
    {
        InventoryAccess::permit($user, 'inventory.count');
        $d = Validator::make($input, ['warehouse_id' => 'required|integer', 'reference' => 'required|string|max:160', 'variant_ids' => 'sometimes|array|max:2000', 'variant_ids.*' => 'integer|distinct'])->validate();

        return DB::transaction(function () use ($user, $d) {
            app(Catalog::class)->lock();
            InventoryAccess::warehouse($user, $d['warehouse_id']);
            if (StockCount::where('warehouse_id', $d['warehouse_id'])->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['warehouse_id' => 'Finish or cancel the existing count for this warehouse.']);
            }
            $query = ProductVariant::where('active', true)->whereHas('product', fn ($q) => $q->where('active', true)->where('item_type', 'stock'));
            if (! empty($d['variant_ids'])) {
                $query->whereIn('id', $d['variant_ids']);
            }
            $variants = $query->get();
            if (! empty($d['variant_ids']) && $variants->count() !== count($d['variant_ids'])) {
                throw ValidationException::withMessages(['variant_ids' => 'Select active physical stock variants belonging to this company.']);
            }
            if ($variants->isEmpty() || $variants->count() > 2000) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select 1–2000 active variants for each count. Split larger catalogues into separate counts.']);
            }
            $count = StockCount::create([...$d, 'actor_id' => $user->id, 'watermark' => StockMovement::where('warehouse_id', $d['warehouse_id'])->max('id') ?? 0]);
            $balances = StockBalance::where('warehouse_id', $d['warehouse_id'])->get()->keyBy('variant_id');
            foreach ($variants as $v) {
                StockCountLine::create(['count_id' => $count->id, 'variant_id' => $v->id, 'expected_quantity' => $balances->get($v->id)?->quantity ?? '0.000']);
            }
            Audit::record('stock_count.started', (string) $count->id);

            return $count->load('lines.variant');
        });
    }

    public function finish(User $user, StockCount $count, array $input): StockCount
    {
        InventoryAccess::permit($user, 'inventory.count');
        $d = Validator::make($input, ['lines' => 'required|array|min:1|max:2000', 'lines.*.variant_id' => 'required|integer|distinct', 'lines.*.counted_quantity' => ['required', 'string', Catalog::QUANTITY]])->validate();

        return DB::transaction(function () use ($user, $count, $d) {
            app(Catalog::class)->lock();
            $count->refresh();
            InventoryAccess::warehouse($user, $count->warehouse_id);
            if ($count->status !== 'draft') {
                throw ValidationException::withMessages(['count' => 'This count has already been finalized.']);
            }
            if ((int) (StockMovement::where('warehouse_id', $count->warehouse_id)->max('id') ?? 0) !== (int) $count->watermark) {
                throw ValidationException::withMessages(['count' => 'Stock changed after this count started. Cancel it and take a new snapshot.']);
            }
            $lines = $count->lines()->get()->keyBy('variant_id');
            $actual = collect($d['lines'])->keyBy('variant_id');
            if ($lines->keys()->sort()->values()->all() !== $actual->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['lines' => 'Submit every snapshot variant exactly once.']);
            }
            $movements = [];
            foreach ($lines as $id => $line) {
                $quantity = BigDecimal::of($actual[$id]['counted_quantity']);
                $delta = $quantity->minus($line->expected_quantity);
                $line->update(['counted_quantity' => (string) $quantity]);
                if (! $delta->isZero()) {
                    $b = StockBalance::where('warehouse_id', $count->warehouse_id)->where('variant_id', $id)->first();
                    $variant = ProductVariant::findOrFail($id);
                    $movements[] = ['variant_id' => $id, 'quantity' => (string) $delta, 'unit_cost' => $b && BigDecimal::of($b->quantity)->isPositive() ? $b->average_cost : $variant->cost_price];
                }
            }
            if ($movements) {
                app(StockLedger::class)->post($user, ['request_id' => Str::uuid()->toString(), 'warehouse_id' => $count->warehouse_id, 'type' => 'count', 'reference' => $count->reference, 'reason' => 'Physical count #'.$count->id, 'lines' => $movements], true);
            }
            $count->update(['status' => 'posted', 'posted_at' => now()]);
            Audit::record('stock_count.posted', (string) $count->id);

            return $count->load('lines.variant');
        }, 3);
    }

    public function cancel(User $user, StockCount $count): void
    {
        InventoryAccess::permit($user, 'inventory.count');
        DB::transaction(function () use ($user, $count) {
            app(Catalog::class)->lock();
            $count->refresh();
            InventoryAccess::warehouse($user, $count->warehouse_id);
            abort_unless($count->status === 'draft', 422, 'Only draft counts can be cancelled.');
            $count->update(['status' => 'cancelled']);
            Audit::record('stock_count.cancelled', (string) $count->id);
        });
    }
}

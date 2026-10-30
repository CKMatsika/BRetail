<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\BranchTransfer;
use App\Models\ChartAccount;
use App\Models\ProductVariant;
use App\Models\TransferEvent;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class BranchTransfers
{
    private function fail(string $m): never
    {
        TradeLedger::fail($m);
    }

    private function lock(User $u, string $p): void
    {
        AccountingAccess::lock($u, $p);
        abort_unless(\App\Models\TenantModule::where('key','inventory')->where('enabled',true)->exists(),403);
    }

    private function transit(string $role = 'inventory_transit', string $name = 'Inventory in transit'): int
    {
        $m = AccountMapping::where('role', $role)->first();
        if ($m) {
            return $m->account_id;
        }
        $code = '1150';
        while (ChartAccount::where('code', $code)->exists()) {
            $code .= 'T';
        }
        $a = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => 'asset', 'report_group' => 'Current assets', 'is_cash' => false, 'active' => true]);
        AccountMapping::create(['role' => $role, 'account_id' => $a->id]);

        return $a->id;
    }

    public function create(User $u, array $input): BranchTransfer
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'source_id' => 'required|integer', 'destination_id' => 'required|integer|different:source_id', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:160', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'transfers.create');
            InventoryAccess::warehouse($u, $d['source_id']);
            InventoryAccess::warehouse($u, $d['destination_id']);
            app(TradeLedger::class)->date($d['date']);
            $hash = PosMath::hash($d);
            $old = BranchTransfer::where('request_id', $d['request_id'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    $this->fail('Transfer request conflict.');
                }

return $old;
            }
            foreach ($d['items'] as $i) {
                ProductVariant::findOrFail($i['variant_id']);
                if (! BigDecimal::of($i['quantity'])->isPositive()) {
                    $this->fail('Transfer quantities must be positive.');
                }
            }

            return BranchTransfer::create([...$d, 'book' => AccountingAccess::book(), 'payload_hash' => $hash]);
        });
    }

    public function transition(User $u, int $id, string $action): BranchTransfer
    {
        return DB::transaction(function () use ($u, $id, $action) {
            abort_unless(in_array($action, ['approve', 'cancel']), 404);
            $this->lock($u, $action === 'approve' ? 'transfers.approve' : 'transfers.create');
            $t = BranchTransfer::findOrFail($id);
            InventoryAccess::warehouse($u, $t->source_id);
            if ($t->book !== AccountingAccess::book()) {
                $this->fail('Transfer belongs to a different operating book.');
            }if ($action === 'approve' && $t->status === 'draft') {
                $t->update(['status' => 'approved']);
            } elseif ($action === 'cancel' && in_array($t->status, ['draft', 'approved'])) {
                $t->update(['status' => 'cancelled']);
            } else {
                $this->fail('This transfer cannot make that transition.');
            }Audit::record('transfer.'.$action, (string) $id);

            return $t;
        });
    }

    public function post(User $u, int $id, string $kind, array $input): TransferEvent
    {
        abort_unless(in_array($kind, ['dispatch', 'receive', 'loss']), 404);
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reason' => ($kind==='loss'?'required':'nullable').'|string|max:2000', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($u, $id, $kind, $d) {
            $this->lock($u, 'transfers.'.($kind==='loss'?'resolve':$kind));
            $t = BranchTransfer::findOrFail($id);
            $warehouse = $kind !== 'receive' ? $t->source_id : $t->destination_id;
            InventoryAccess::warehouse($u, $warehouse);
            $hash = PosMath::hash([$id, $kind, $d]);
            $old = TransferEvent::where('request_id', $d['request_id'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    $this->fail('Transfer event request conflict.');
                }

return $old;
            }
            if ($t->book !== AccountingAccess::book() || $d['date'] < $t->date) {
                $this->fail('Transfer date or operating book mismatch.');
            }app(TradeLedger::class)->date($d['date']);
            $events = TransferEvent::where('transfer_id', $id)->get();
            if ($events->contains(fn ($e) => $e->date > $d['date'])) {
                $this->fail('Transfer events must be chronological.');
            }
            if (($kind === 'dispatch' && $t->status !== 'approved') || ($kind !== 'dispatch' && ! in_array($t->status, ['in_transit', 'partially_received']))) {
                $this->fail('Transfer is not ready for this operation.');
            }
            $ordered = collect($t->items)->keyBy('variant_id');
            $sent = [];
            $received = [];
            $values = [];
            foreach ($events as $e) {
                foreach ($e->items as $i) {
                    $v = $i['variant_id'];
                    if ($e->kind === 'dispatch') {
                        $sent[$v] = $i['quantity'];
                        $values[$v] = $i['value'];
                    } else {
                        $received[$v] = (string) BigDecimal::of($received[$v] ?? '0')->plus($i['quantity']);
                    }
                }
            }
            $lines = [];
            $items = [];
            foreach ($d['items'] as $i) {
                $v = $i['variant_id'];
                $qty = BigDecimal::of($i['quantity']);
                if (! $ordered->has($v) || ! $qty->isPositive()) {
                    $this->fail('Invalid transfer item.');
                }
                if ($kind === 'dispatch') {
                    if (! $qty->isEqualTo($ordered[$v]['quantity'])) {
                        $this->fail('Dispatch all approved quantities together.');
                    }$lines[] = ['variant_id' => $v, 'quantity' => (string) $qty->negated()];
                } else {
                    if ($qty->plus($received[$v] ?? '0')->isGreaterThan($sent[$v] ?? '0')) {
                        $this->fail('Receipt exceeds quantity in transit.');
                    }$value = PosMath::portion($values[$v], $sent[$v], $received[$v] ?? '0', (string) $qty, 4);
                    $lines[] = ['variant_id' => $v, 'quantity' => (string) $qty, 'unit_cost' => (string) BigDecimal::of($value)->dividedBy($qty, 4, RoundingMode::HalfUp), 'value_override' => $value];
                    $items[] = [...$i, 'value' => $value];
                }
            }
            if ($kind === 'dispatch' && count($lines) !== count($t->items)) {
                $this->fail('Dispatch must include all approved items.');
            }
            $stock = $kind==='loss' ? null : app(StockLedger::class)->transfer($u, ['request_id' => $d['request_id'], 'warehouse_id' => $warehouse, 'type' => 'transfer_'.($kind === 'dispatch' ? 'dispatch' : 'receive'), 'reference' => $t->reference, 'reason' => 'Branch transfer '.$id, 'lines' => $lines]);
            if ($kind === 'dispatch') {
                foreach ($stock->movements as $m) {
                    $items[] = ['variant_id' => $m->variant_id, 'quantity' => (string) BigDecimal::of($m->quantity)->abs(), 'value' => (string) BigDecimal::of($m->value)->abs()];
                }
            }
            $event = TransferEvent::create(['transfer_id' => $id, 'request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => $kind, 'date' => $d['date'], 'items' => array_map(fn($i)=>[...$i,'reason'=>$d['reason']??null],$items), 'created_at' => now()]);
            $l = app(AccountingLedger::class);
            $value = PosMath::sum(array_column($items, 'value'), 4);
            $transit = $this->transit();
            // Company-wide authority is required for paired branch postings on receipt.
            if ($kind === 'receive') {
                AccountingAccess::branch($u, Warehouse::findOrFail($t->source_id)->branch_id, true);
            }
            $branch = Warehouse::findOrFail($warehouse)->branch_id;
            $post = function (int $b, array $ls, string $suffix) use ($u, $t, $d, $event, $l) {
                $l->system($u, ['book' => $t->book, 'branch_id' => $b, 'date' => $d['date'], 'reference' => $t->reference, 'description' => 'Branch transfer '.$t->id, 'cashflow' => 'none', 'source_type' => 'branch_transfer', 'source_id' => $event->id.$suffix, 'lines' => $ls]);
            };
            if ($kind === 'loss') {
                $post($branch, [$l->line('inventory_loss',$value),$l->line($transit,'0',$value)],'L');
            } elseif ($kind === 'dispatch') {
                $post($branch, [$l->line($transit, $value), $l->line('inventory', '0', $value)], 'D');
            } else {
                $source = Warehouse::findOrFail($t->source_id)->branch_id;
                if ($source !== $branch) {
                    $clearing = $this->transit('branch_clearing', 'Inter-branch clearing');
                    $post($source, [$l->line($clearing, $value), $l->line($transit, '0', $value)], 'S');
                    $post($branch, [$l->line('inventory', $value), $l->line($clearing, '0', $value)], 'R');
                } else {
                    $post($branch, [$l->line('inventory', $value), $l->line($transit, '0', $value)], 'R');
                }
            }
            $complete = true;
            foreach ($t->items as $i) {
                $total = BigDecimal::of($received[$i['variant_id']] ?? '0');
                foreach ($items as $r) {
                    if ($r['variant_id'] === $i['variant_id']) {
                        $total = $total->plus($r['quantity']);
                    }
                }if (! $total->isEqualTo($i['quantity'])) {
                    $complete = false;
                }
            }
            $t->update(['status' => $kind === 'dispatch' ? 'in_transit' : ($complete ? 'completed' : 'partially_received')]);
            Audit::record('transfer.'.$kind,(string) $event->id);

            return $event;
        }, 3);
    }
}

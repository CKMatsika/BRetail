<?php

namespace App\Services;

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\StockDocument;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class AccountingBridge
{
    public function ready(): bool
    {
        return TenantModule::where('key', 'accounting')->where('enabled', true)->exists();
    }

    private function context(?string $book = null): ?string
    {
        if (! $this->ready()) {
            return null;
        }$s = AccountingAccess::setting();

        return $book ?? ($s->live_enabled ? 'live' : 'training');
    }

    private function post(User $u, string $type, string $id, string $book, int $branch, string $reference, string $description, array $lines, string $cashflow = 'operating', ?string $date = null): void
    {
        app(AccountingLedger::class)->system($u, ['book' => $book, 'source_type' => $type, 'source_id' => $id, 'branch_id' => $branch, 'date' => $date ?? AccountingLedger::today(), 'reference' => $reference, 'description' => $description, 'cashflow' => $cashflow, 'lines' => $lines]);
    }

    public function stock(User $u, StockDocument $doc): void
    {
        $book = $this->context();
        if (! $book || in_array($doc->type, ['sale', 'customer_return', 'purchase', 'goods_receipt', 'credit_sale', 'credit_return', 'transfer_dispatch', 'transfer_receive'], true)) {
            return;
        }$value = PosMath::sum($doc->movements()->get()->pluck('value'), 4);
        $v = BigDecimal::of($value);
        $l = app(AccountingLedger::class);
        $branch = Warehouse::findOrFail($doc->warehouse_id)->branch_id;
        $offset = $doc->type === 'opening' ? 'opening_equity' : ($v->isNegative() ? 'inventory_loss' : 'inventory_gain');
        $lines = $v->isNegative() ? [$l->line($offset, (string) $v->abs()), $l->line('inventory', '0', (string) $v->abs())] : [$l->line('inventory', $value), $l->line($offset, '0', $value)];
        // Mixed-sign count documents must post every increase and decrease independently.
        if ($doc->type !== 'opening') {
            $lines = [];
            foreach ($doc->movements()->get() as $m) {
                $a = BigDecimal::of($m->value);
                $lines[] = $a->isNegative() ? $l->line('inventory_loss', (string) $a->abs()) : $l->line('inventory', (string) $a);
                $lines[] = $a->isNegative() ? $l->line('inventory', '0', (string) $a->abs()) : $l->line('inventory_gain', '0', (string) $a);
            }
        }
        $this->post($u, 'stock', (string) $doc->id, $book, $branch, $doc->reference, $doc->reason, $lines, 'none');
    }

    public function sale(User $u, PosSale $sale): void
    {
        if (! $this->context($sale->mode)) {
            return;
        }$l = app(AccountingLedger::class);
        $lines = [];
        foreach ($sale->payments()->get() as $p) {
            $role = match ($p->method) { 'bank_transfer' => 'bank', 'loyalty_points' => 'loyalty_liability', default => $p->method };
            $lines[] = $l->line($role, $p->amount);
        }$lines[] = $l->line('sales', '0', PosMath::sum($sale->items()->get()->pluck('net'), 4));
        $lines[] = $l->line('tax_payable', '0', $sale->tax);
        $cost = PosMath::sum($sale->items()->get()->pluck('cost_value'), 4);
        $lines[] = $l->line('cogs', $cost);
        $lines[] = $l->line('inventory', '0', $cost);
        $this->post($u, 'pos_sale', (string) $sale->id, $sale->mode, Warehouse::findOrFail($sale->warehouse_id)->branch_id, $sale->receipt_number, 'POS sale with actual inventory carrying cost', $lines);
    }

    public function refund(User $u, PosReturn $return): void
    {
        if (! $this->context($return->mode)) {
            return;
        }$l = app(AccountingLedger::class);
        $lines = [$l->line('returns', $return->net), $l->line('tax_payable', $return->tax)];
        foreach ($return->payments()->get() as $p) {
            $role = match ($p->method) { 'bank_transfer' => 'bank', 'loyalty_points' => 'loyalty_liability', default => $p->method };
            $lines[] = $l->line($role, '0', $p->amount);
        }$cost = PosMath::sum($return->items()->get()->pluck('cost_value'), 4);
        $lines[] = $l->line('inventory', $cost);
        $lines[] = $l->line('cogs', '0', $cost);
        $this->post($u, 'pos_return', (string) $return->id, $return->mode, Warehouse::findOrFail($return->warehouse_id)->branch_id, $return->receipt_number, 'POS return at original sale values and carrying cost', $lines);
    }

    public function openShift(User $u, PosSession $s): void
    {
        if (! $this->context($s->mode)) {
            return;
        }$l = app(AccountingLedger::class);
        $branch = Warehouse::findOrFail($s->warehouse_id)->branch_id;
        if (BigDecimal::of($l->balance($l->account('cash_safe')->id, $s->mode, $branch, AccountingLedger::today()))->isLessThan($s->opening_cash)) {
            throw ValidationException::withMessages(['opening_cash' => 'Record verified safe funds in the cashbook/opening balances before funding this drawer.']);
        }$this->post($u, 'shift_float', (string) $s->id, $s->mode, $branch, 'SHIFT-'.$s->id, 'Transfer opening float from safe to drawer', [$l->line('cash', $s->opening_cash), $l->line('cash_safe', '0', $s->opening_cash)], 'none');
    }

    public function closeShift(User $u, PosSession $s): void
    {
        if (! $this->context($s->mode)) {
            return;
        }$l = app(AccountingLedger::class);
        $difference = BigDecimal::of($s->counted_cash)->minus($s->expected_cash);
        $lines = [$l->line('cash_safe', $s->counted_cash), $l->line('cash', '0', $s->expected_cash), $difference->isNegative() ? $l->line('cash_short', (string) $difference->abs()) : $l->line('cash_over', '0', (string) $difference)];
        $this->post($u, 'shift_close', (string) $s->id, $s->mode, Warehouse::findOrFail($s->warehouse_id)->branch_id, 'SHIFT-'.$s->id, 'Return counted cash and record drawer variance', $lines);
    }
}

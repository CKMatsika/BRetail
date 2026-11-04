<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\CustomerCredit;
use App\Models\PosCustomer;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TradeLedger
{
    public function assertBranchClosable(int $branch): void
    {
        app(\App\Services\PhaseSixAccess::class)->branchClosable($branch);
        $warehouses = Warehouse::where('branch_id', $branch)->pluck('id');
        if (PurchaseOrder::whereIn('warehouse_id', $warehouses)->whereIn('status', ['draft', 'approved', 'partially_received'])->exists()) {
            self::fail('Finish or cancel purchase orders before deactivating this branch.');
        }
        foreach (TradeDocument::whereIn('warehouse_id', $warehouses)->whereIn('kind', ['bill', 'invoice', 'opening_payable', 'opening_receivable'])->get() as $doc) {
            if (BigDecimal::of($this->outstanding($doc))->isPositive()) {
                self::fail('Settle supplier and customer balances before deactivating this branch.');
            }
        }
        foreach (TradeDocument::whereIn('warehouse_id', $warehouses)->where('kind', 'receipt')->get() as $receipt) {
            $billed = [];
            foreach (TradeDocument::where('kind', 'bill')->where('parent_id', $receipt->id)->get() as $bill) {
                foreach ($bill->items as $item) {
                    $billed[$item['variant_id']] = (string) BigDecimal::of($billed[$item['variant_id']] ?? '0')->plus($item['quantity']);
                }
            }
            foreach ($receipt->items as $item) {
                if (BigDecimal::of($item['quantity'])->isGreaterThan($billed[$item['variant_id']] ?? '0')) {
                    self::fail('Match outstanding goods receipts to supplier bills before deactivating this branch.');
                }
            }
        }
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }

    public function lock(User $u, string $permission): void
    {
        AccountingAccess::lock($u, $permission);
        $module = str_starts_with($permission, 'purchasing.') ? 'purchasing' : 'sales';
        abort_unless(TenantModule::where('key', $module)->where('enabled', true)->exists(), 403);
        AccountingAccess::setting();
    }

    public function existing(string $request, array $payload): ?TradeDocument
    {
        $old = TradeDocument::where('request_id', $request)->first();
        if ($old && ! hash_equals($old->payload_hash, PosMath::hash($payload))) {
            self::fail('Request ID was already used with different data.');
        }

return $old;
    }

    public function date(string $date): void
    {
        if ($date > AccountingLedger::today()) {
            self::fail('Business documents cannot be future dated.');
        }
    }

    public function document(User $u, string $kind, array $d, array $items, string $net, string $tax, string $total, array $payload): TradeDocument
    {
        $this->date($d['date']);
        $w = InventoryAccess::warehouse($u, $d['warehouse_id']);
        $n = (TradeDocument::max('id') ?? 0) + 1;

        return TradeDocument::create([...$d, 'kind' => $kind, 'number' => strtoupper($kind).'-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'book' => $d['book'] ?? AccountingAccess::book(), 'actor_id' => $u->id, 'items' => $items, 'net' => $net, 'tax' => $tax, 'total' => $total, 'payload_hash' => PosMath::hash($payload), 'created_at' => now()]);
    }

    public function post(User $u, TradeDocument $d, array $lines, string $cashflow = 'none'): void
    {
        app(AccountingLedger::class)->system($u, ['book' => $d->book, 'branch_id' => Warehouse::findOrFail($d->warehouse_id)->branch_id, 'date' => $d->date, 'reference' => $d->kind === 'invoice_reversal' ? $d->reference : $d->display_number, 'description' => $d->kind.' '.$d->party_name, 'source_type' => 'trade_'.$d->kind, 'source_id' => (string) $d->id, 'cashflow' => $cashflow, 'lines' => $lines]);
        Audit::record('trade.'.$d->kind.'_posted', (string) $d->id);
    }

    public function outstanding(TradeDocument $d): string
    {
        return (string) BigDecimal::of($d->total)->minus(PosMath::sum(TradeAllocation::where('target_id', $d->id)->pluck('amount'), 4))->toScale(4);
    }

    public function accrual(): ChartAccount
    {
        if (AccountMapping::where('role', 'receipt_accrual')->exists()) {
            return app(AccountingLedger::class)->account('receipt_accrual');
        }
        $code = '2050';
        while (ChartAccount::where('code', $code)->exists()) {
            $code .= 'R';
        }
        $a = ChartAccount::create(['code' => $code, 'name' => 'Goods received not invoiced', 'type' => 'liability', 'report_group' => 'Current liabilities', 'is_cash' => false, 'active' => true]);
        AccountMapping::create(['role' => 'receipt_accrual', 'account_id' => $a->id]);

        return $a;
    }

    public function credit(User $u, array $input): CustomerCredit
    {
        return DB::transaction(function () use ($u, $input) {
            $this->lock($u, 'sales.configure');
            $d = Validator::make($input, ['customer_id' => 'required|integer', 'credit_limit' => ['required', 'string', PosMath::MONEY], 'terms_days' => 'required|integer|min:0|max:365', 'active' => 'required|boolean'])->validate();
            PosCustomer::findOrFail($d['customer_id']);
            abort_unless($u->role->company_wide, 403, 'Credit limits require company-wide authority.');
            $c = CustomerCredit::updateOrCreate(['customer_id' => $d['customer_id']], $d);
            Audit::record('sales.credit_configured', (string) $c->id);

            return $c;
        });
    }

    public function settle(User $u, int $id, array $input): TradeDocument
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $target = TradeDocument::findOrFail($id);
            $purchase = in_array($target->kind, ['bill', 'opening_payable'], true);
            $this->lock($u, $purchase ? 'purchasing.pay' : 'sales.receive');
             $target->refresh();
            if (isset($input['foreign_account_id'])) return app(ForeignTradeSettlement::class)->settle($u,$target,$input);
            if (! in_array($target->kind, ['bill', 'invoice', 'opening_payable', 'opening_receivable'], true)) {
                self::fail('Choose a supplier bill or customer invoice.');
            }InventoryAccess::warehouse($u, $target->warehouse_id);
            $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'amount' => ['required', 'string', PosMath::MONEY], 'account_id' => 'required|integer', 'reference' => 'required|string|max:100'])->validate();
            $payload = [...$d, 'target_id' => $id];
            if ($old = $this->existing($d['request_id'], $payload)) {
                return $old;
            }
            if ($d['date'] < $target->date || $target->book !== AccountingAccess::book()) {
                self::fail('Settlement must follow the original document in its operating book.');
            }if($target->currency_snapshot)self::fail('Settle foreign-denominated documents in their original currency.');
            $amount = BigDecimal::of($d['amount']);
            if (! $amount->isPositive() || $amount->isGreaterThan($this->outstanding($target))) {
                self::fail('Settlement exceeds the outstanding amount.');
            }$a = ChartAccount::findOrFail($d['account_id']);
            $l = app(AccountingLedger::class);
            if (! $a->active || ! $a->is_cash || $a->id === $l->account('cash')->id) {
                self::fail('Choose an active bank, mobile or safe account.');
            }$w = Warehouse::findOrFail($target->warehouse_id);
            if ($purchase && BigDecimal::of($l->balance($a->id, $target->book, $w->branch_id, $d['date']))->isLessThan($amount)) {
                self::fail('Insufficient recorded funds.');
            }
            $doc = $this->document($u, $purchase ? 'supplier_payment' : 'customer_receipt', [...collect($d)->except('amount', 'account_id')->all(), 'warehouse_id' => $target->warehouse_id, 'supplier_id' => $target->supplier_id, 'customer_id' => $target->customer_id, 'parent_id' => $target->id, 'party_name' => $target->party_name], [], (string) $amount, '0', (string) $amount, $payload);
            TradeAllocation::create(['document_id' => $doc->id, 'target_id' => $target->id, 'amount' => (string) $amount]);
            $this->post($u, $doc, $purchase ? [$l->line('payable', (string) $amount), $l->line($a->id, '0', (string) $amount)] : [$l->line($a->id, (string) $amount), $l->line('receivable', '0', (string) $amount)], 'operating');

            return $doc;
        }, 3);
    }

    public function opening(User $u, array $input): TradeDocument
    {
        return DB::transaction(function () use ($u, $input) {
            $d = Validator::make($input, ['request_id' => 'required|uuid', 'kind' => 'required|in:opening_payable,opening_receivable', 'warehouse_id' => 'required|integer', 'party_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:date', 'reference' => 'required|string|max:100', 'amount' => ['required', 'string', PosMath::MONEY]])->validate();
            $purchase = $d['kind'] === 'opening_payable';
            $this->lock($u, $purchase ? 'purchasing.bill' : 'sales.configure');
            abort_unless($u->role->company_wide, 403);
            if ($old = $this->existing($d['request_id'], $d)) {
                return $old;
            }$w = InventoryAccess::warehouse($u, $d['warehouse_id']);
            $role = $purchase ? 'payable' : 'receivable';
            $l = app(AccountingLedger::class);
            $book = AccountingAccess::book();
            $balance = BigDecimal::of($l->balance($l->account($role)->id, $book, $w->branch_id));
            if ($purchase) {
                $balance = $balance->negated();
            }$sub = BigDecimal::zero();
            foreach (TradeDocument::where('book', $book)->whereIn('kind', $purchase ? ['bill', 'opening_payable'] : ['invoice', 'opening_receivable'])->whereIn('warehouse_id', Warehouse::where('branch_id', $w->branch_id)->select('id'))->get() as $doc) {
                $sub = $sub->plus($this->outstanding($doc));
            }$amount = BigDecimal::of($d['amount']);
            if (! $amount->isPositive() || $amount->isGreaterThan($balance->minus($sub))) {
                self::fail('Opening allocation exceeds unallocated control-account balance.');
            }$party = $purchase ? Supplier::findOrFail($d['party_id']) : PosCustomer::findOrFail($d['party_id']);
            $doc = $this->document($u, $d['kind'], [...collect($d)->except('kind', 'party_id', 'amount')->all(), $purchase ? 'supplier_id' : 'customer_id' => $party->id, 'party_name' => $party->name], [], (string) $amount, '0', (string) $amount, $d);
            Audit::record('trade.opening_allocated', (string) $doc->id);

            return $doc;
        });
    }

    public function report(User $u): array
    {
        return DB::transaction(function () use ($u) {
            AccountingAccess::lock($u);
            $rows = [];
            $book = AccountingAccess::book();
            $allowed = [];
            if ($u->canDo('purchasing.view') && TenantModule::where('key', 'purchasing')->where('enabled', true)->exists()) {
                $allowed = [...$allowed, 'bill', 'opening_payable'];
            }if ($u->canDo('sales.view') && TenantModule::where('key', 'sales')->where('enabled', true)->exists()) {
                $allowed = [...$allowed, 'invoice', 'opening_receivable'];
            }abort_unless($allowed, 403);
            $ids = AccountingAccess::branches($u)->pluck('id');
            $warehouses = Warehouse::whereIn('branch_id', $ids)->pluck('id');
            foreach (TradeDocument::current()->where('book', $book)->whereIn('kind', $allowed)->whereIn('warehouse_id', $warehouses)->get() as $d) {
                $out = $this->outstanding($d);
                $days = max(0, (int) CarbonImmutable::parse($d->due_on)->diffInDays(CarbonImmutable::parse(AccountingLedger::today()), false));
                $rows[] = [...$d->only('id', 'number', 'kind', 'party_name', 'date', 'due_on', 'total', 'warehouse_id'), 'number' => $d->display_number, 'outstanding' => $out, 'days_overdue' => $days, 'bucket' => $days === 0 ? 'current' : ($days <= 30 ? '1–30' : ($days <= 60 ? '31–60' : ($days <= 90 ? '61–90' : '90+')))];
            }$controls = [];
            $l = app(AccountingLedger::class);
            foreach (['payable' => ['bill', 'opening_payable'], 'receivable' => ['invoice', 'opening_receivable']] as $role => $kinds) {
                if (! array_intersect($kinds, $allowed)) {
                    continue;
                }foreach ($ids as $branch) {
                    $ledger = BigDecimal::of($l->balance($l->account($role)->id, $book, $branch));
                    if ($role === 'payable') {
                        $ledger = $ledger->negated();
                    }$wids = Warehouse::where('branch_id', $branch)->pluck('id');
                    $sub = PosMath::sum(array_column(array_values(array_filter($rows,fn ($r) => in_array($r['kind'],$kinds,true) && $wids->contains($r['warehouse_id']))),'outstanding'),4);
                    $controls[] = ['role' => $role, 'branch_id' => $branch, 'ledger' => (string) $ledger->toScale(4), 'subledger' => $sub, 'unallocated' => (string) $ledger->minus($sub)->toScale(4)];
                }
            }

return ['book' => $book, 'date' => AccountingLedger::today(), 'aging' => $rows, 'controls' => $controls];
        });
    }
}

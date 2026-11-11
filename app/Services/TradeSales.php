<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerCredit;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TradeSales
{
    public function quote(User $u, array $input, ?int $revises = null, bool $preview = false): TradeDocument|array
    {
        return $this->prepareQuote($u, $input, $revises, $preview, 'sales.quote');
    }

    public function invoicePreview(User $u, array $input): array
    {
        return $this->prepareQuote($u, $input, null, true, 'sales.invoice');
    }

    private function prepareQuote(User $u, array $input, ?int $revises, bool $preview, string $permission): TradeDocument|array
    {
        $d = Validator::make($input, ['prices_include_vat' => 'sometimes|boolean', 'currency' => 'nullable|string|size:3', 'request_id' => 'required|uuid', 'warehouse_id' => 'required|integer', 'customer_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:date', 'reference' => 'required|string|max:100', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'], 'items.*.unit_price' => ['required', 'string', Catalog::MONEY], 'items.*.discount_percent' => ['required', 'string', 'numeric', 'between:0,100', 'decimal:0,2']])->validate();

        return DB::transaction(function () use ($u, $d, $revises, $preview, $permission) {
            $t = app(TradeLedger::class);
            $t->lock($u, $permission);
            InventoryAccess::warehouse($u, $d['warehouse_id']);
            $payload = $revises ? [...$d, 'revises' => $revises] : $d;
            if (! $preview && ($old = $t->existing($d['request_id'], $payload))) {
                return $old;
            }
            if ($revises) {
                $previous = TradeDocument::where('kind', 'quote')->findOrFail($revises);
                InventoryAccess::warehouse($u, $previous->warehouse_id);
                if ($previous->book !== AccountingAccess::book() || TradeDocument::where('parent_id', $previous->id)->whereIn('kind', ['quote', 'invoice'])->exists()) {
                    TradeLedger::fail('This quotation was already revised or invoiced. Open its latest version.');
                }
            }
            $t->date($d['date']);
            $customer = PosCustomer::findOrFail($d['customer_id']);
            $items = [];
            $company = Company::firstOrFail();
            foreach ($d['items'] as $i) {
                $v = ProductVariant::with('product')->findOrFail($i['variant_id']);
                if (! $v->active || ! $v->product->active || ! BigDecimal::of($i['quantity'])->isPositive()) {
                    TradeLedger::fail('Choose active products and positive quantities.');
                }$tax = TaxRateResolver::forClass($company, $v->product->tax_class);
                $items[] = [...$i, 'prices_include_vat' => (bool) ($d['prices_include_vat'] ?? false), 'description' => $v->product->name.' / '.$v->name, 'sku' => $v->sku, 'tax_rate' => $tax, ...PosMath::line($i['quantity'], $i['unit_price'], $i['discount_percent'], $tax, (bool) ($d['prices_include_vat'] ?? false))];
            }
            $converted = app(TradeCurrency::class)->convert($items, $d['currency'] ?? null, $d['date']);
            $items = $converted['items'];
            if ($preview) {
                return ['items' => $items, 'currency_snapshot' => $converted['snapshot'], 'net' => PosMath::sum(array_column($items, 'net')), 'tax' => PosMath::sum(array_column($items, 'tax')), 'total' => PosMath::sum(array_column($items, 'total'))];
            }
            $doc = $t->document($u, 'quote', [...collect($d)->except('items', 'currency', 'prices_include_vat')->all(), 'parent_id' => $revises, 'party_name' => $customer->name, 'currency_snapshot' => $converted['snapshot']], $items, PosMath::sum(array_column($items, 'net')), PosMath::sum(array_column($items, 'tax')), PosMath::sum(array_column($items, 'total')), $payload);
            Audit::record($revises ? 'sales.quote_revised' : 'sales.quote_created', (string) $doc->id);

            return $doc;
        });
    }

    public function invoice(User $u, int $id, array $input): TradeDocument
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'accepted' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $t = app(TradeLedger::class);
            $t->lock($u, 'sales.invoice');
            $q = TradeDocument::where('kind', 'quote')->findOrFail($id);
            InventoryAccess::warehouse($u, $q->warehouse_id);
            $payload = [...$d, 'quote_id' => $id];
            if ($old = $t->existing($d['request_id'], $payload)) {
                return $old;
            }
            if ($q->book !== AccountingAccess::book() || $d['date'] < $q->date || $d['date'] > $q->due_on || AccountingLedger::today() > $q->due_on) {
                TradeLedger::fail('Quotation expired or belongs to a different operating book.');
            }if (TradeDocument::whereIn('kind', ['invoice', 'quote'])->where('parent_id', $q->id)->exists()) {
                TradeLedger::fail('Quotation was already invoiced or revised. Open its latest version.');
            }
            $profile = $this->assertCredit($q->customer_id, $d['date'], $q->total, $q->book);
            $stock = app(StockLedger::class)->trade($u, ['request_id' => $d['request_id'], 'warehouse_id' => $q->warehouse_id, 'type' => 'credit_sale', 'reference' => $q->display_number, 'reason' => 'Accepted credit quotation', 'lines' => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => (string) BigDecimal::of($i['quantity'])->negated()], $q->items)]);
            $costs = $stock->movements->keyBy('variant_id');
            $items = array_map(fn ($i) => [...$i, 'cost_value' => (string) BigDecimal::of($costs->get($i['variant_id'])?->value ?? '0')->negated()], $q->items);
            $due = CarbonImmutable::parse($d['date'])->addDays($profile->terms_days)->toDateString();
            $doc = $t->document($u, 'invoice', ['request_id' => $d['request_id'], 'date' => $d['date'], 'due_on' => $due, 'reference' => $q->display_number, 'warehouse_id' => $q->warehouse_id, 'customer_id' => $q->customer_id, 'party_name' => $q->party_name, 'parent_id' => $q->id, 'currency_snapshot' => $q->currency_snapshot], $items, $q->net, $q->tax, $q->total, $payload);
            $l = app(AccountingLedger::class);
            $cost = PosMath::sum(array_column($items, 'cost_value'), 4);
            $t->post($u, $doc, [$l->line('receivable', $q->total), $l->line('sales', '0', $q->net), $l->line('tax_payable', '0', $q->tax), $l->line('cogs', $cost), $l->line('inventory', '0', $cost)]);

            return $doc;
        }, 3);
    }

    public function assertCredit(int $customer, string $date, string $total, string $book): CustomerCredit
    {
        $t = app(TradeLedger::class);
        $profile = CustomerCredit::where('customer_id', $customer)->first();
        if (! $profile || ! $profile->active) {
            TradeLedger::fail('Configure and activate this customer credit profile first.');
        }$open = BigDecimal::zero();
        foreach (TradeDocument::where('book', $book)->where('customer_id', $customer)->whereIn('kind', ['invoice', 'opening_receivable'])->get() as $doc) {
            $due = $t->outstanding($doc);
            $open = $open->plus($due);
            if (BigDecimal::of($due)->isPositive() && $doc->due_on < max($date, AccountingLedger::today())) {
                TradeLedger::fail('Customer has overdue invoices. Record their payment before extending credit.');
            }
        }if ($open->plus($total)->isGreaterThan($profile->credit_limit) || ! BigDecimal::of($total)->isPositive()) {
            TradeLedger::fail('Invoice exceeds customer credit limit or has no positive total.');
        }
        $ledger = app(AccountingLedger::class);
        $sub = BigDecimal::zero();
        foreach (TradeDocument::where('book', $book)->whereIn('kind', ['invoice', 'opening_receivable'])->get() as $entry) {
            $sub = $sub->plus($t->outstanding($entry));
        }if (! BigDecimal::of($ledger->balance($ledger->account('receivable')->id, $book))->minus($sub)->isZero()) {
            TradeLedger::fail('Allocate existing receivable control balances to customers before extending credit.');
        }

        return $profile;
    }

    public function creditReturn(User $u, int $id, array $input): TradeDocument
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:100', 'notes' => 'required|string|max:2000', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $t = app(TradeLedger::class);
            $t->lock($u, 'sales.return');
            $invoice = TradeDocument::where('kind', 'invoice')->findOrFail($id);
            InventoryAccess::warehouse($u, $invoice->warehouse_id);
            $payload = [...$d, 'invoice_id' => $id];
            if ($old = $t->existing($d['request_id'], $payload)) {
                return $old;
            }if ($invoice->book !== AccountingAccess::book() || $d['date'] < $invoice->date) {
                TradeLedger::fail('Return must follow the invoice in its operating book.');
            }$prior = [];
            foreach (TradeDocument::where('kind', 'credit_return')->where('parent_id', $id)->get() as $r) {
                foreach ($r->items as $i) {
                    $prior[$i['variant_id']] = (string) BigDecimal::of($prior[$i['variant_id']] ?? '0')->plus($i['quantity']);
                }
            }$original = collect($invoice->items)->keyBy('variant_id');
            $items = [];
            foreach ($d['items'] as $i) {
                $o = $original->get($i['variant_id']);
                $q = BigDecimal::of($i['quantity']);
                $p = $prior[$i['variant_id']] ?? '0';
                if (! $o || ! $q->isPositive() || $q->plus($p)->isGreaterThan($o['quantity'])) {
                    TradeLedger::fail('Return exceeds remaining invoice quantity.');
                }$items[] = [...$o, 'quantity' => (string) $q, 'net' => PosMath::portion($o['net'], $o['quantity'], $p, (string) $q), 'tax' => PosMath::portion($o['tax'], $o['quantity'], $p, (string) $q), 'cost_value' => PosMath::portion($o['cost_value'], $o['quantity'], $p, (string) $q, 4)];
            }foreach ($items as &$item) {
                $item['total'] = (string) BigDecimal::of($item['net'])->plus($item['tax']);
            }unset($item);
            $net = PosMath::sum(array_column($items, 'net'));
            $tax = PosMath::sum(array_column($items, 'tax'));
            $total = (string) BigDecimal::of($net)->plus($tax);
            if (BigDecimal::of($total)->isGreaterThan($t->outstanding($invoice))) {
                TradeLedger::fail('This return exceeds unpaid credit. Paid invoices require a separately reviewed cash refund.');
            }
            $returnSnapshot = null;
            if ($invoice->currency_snapshot) {
                $converted = app(TradeCurrency::class)->originalReturn($invoice, $items, $prior);
                $items = $converted['items'];
                $returnSnapshot = $converted['snapshot'];
            }
            $doc = $t->document($u, 'credit_return', [...collect($d)->except('items')->all(), 'warehouse_id' => $invoice->warehouse_id, 'customer_id' => $invoice->customer_id, 'party_name' => $invoice->party_name, 'parent_id' => $id, 'currency_snapshot' => $returnSnapshot], $items, $net, $tax, $total, $payload);
            app(StockLedger::class)->trade($u, ['request_id' => $d['request_id'], 'warehouse_id' => $invoice->warehouse_id, 'type' => 'credit_return', 'reference' => $doc->number, 'reason' => $d['notes'], 'lines' => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'unit_cost' => (string) BigDecimal::of($i['cost_value'])->dividedBy($i['quantity'], 4, RoundingMode::HalfUp), 'value_override' => $i['cost_value']], $items)]);
            TradeAllocation::create(['document_id' => $doc->id, 'target_id' => $invoice->id, 'amount' => $total, 'original_amount' => $returnSnapshot['original_amount'] ?? null]);
            $l = app(AccountingLedger::class);
            $cost = PosMath::sum(array_column($items, 'cost_value'), 4);
            $t->post($u, $doc, [$l->line('returns', $net), $l->line('tax_payable', $tax), $l->line('receivable', '0', $total), $l->line('inventory', $cost), $l->line('cogs', '0', $cost)]);

            return $doc;
        }, 3);
    }
}

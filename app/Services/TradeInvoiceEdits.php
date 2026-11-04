<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\TradeDocumentDetail;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class TradeInvoiceEdits
{
    private function invoice(User $user, int $id): TradeDocument
    {
        app(TradeLedger::class)->lock($user, 'sales.invoice');
        $invoice = TradeDocument::where('kind', 'invoice')->findOrFail($id);
        InventoryAccess::warehouse($user, $invoice->warehouse_id);
        if ($invoice->book !== AccountingAccess::book() || ! TradeDocument::current()->whereKey($id)->exists()) {
            TradeLedger::fail('Open the current invoice in its operating book before editing.');
        }

        return $invoice;
    }

    public function details(User $user, int $id, array $input): TradeDocument
    {
        $data = Validator::make($input, ['request_id' => 'required|uuid', 'reference' => 'required|string|max:100', 'notes' => 'nullable|string|max:2000'])->validate();

        return DB::transaction(function () use ($user, $id, $data) {
            $invoice = $this->invoice($user, $id);
            $hash = PosMath::hash([...$data, 'invoice_id' => $id]);
            $existing = TradeDocumentDetail::where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    TradeLedger::fail('Request ID was already used with different data.');
                }

                return $invoice;
            }
            TradeDocumentDetail::create([...$data, 'document_id' => $id, 'actor_id' => $user->id, 'payload_hash' => $hash, 'created_at' => now()]);
            Audit::record('sales.invoice_details_updated', (string) $id);

            return $invoice;
        });
    }

    public function amend(User $user, int $id, array $input, bool $preview = false): TradeDocument|array
    {
        return DB::transaction(function () use ($user, $id, $input, $preview) {
            app(TradeLedger::class)->lock($user, 'sales.invoice');
            $original = TradeDocument::where('kind', 'invoice')->findOrFail($id);
            InventoryAccess::warehouse($user, $original->warehouse_id);
            $invoiceCurrency = $original->currency_snapshot['currency'] ?? Company::firstOrFail()->base_currency;
            $input['currency'] ??= $invoiceCurrency;
            $payload = [...$input, 'amends_invoice' => $id];
            if (! $preview && isset($input['request_id']) && ($existing = app(TradeLedger::class)->existing($input['request_id'], $payload))) {
                return $existing;
            }
            $original = $this->invoice($user, $id);
            if (TradeAllocation::where('target_id', $id)->exists()) {
                TradeLedger::fail('This invoice has payments or credit returns. Edit reference/notes only; use a credit note for financial changes.');
            }
            $values = app(TradeSales::class)->invoicePreview($user, $input);
            if ((int) $input['customer_id'] !== $original->customer_id || (int) $input['warehouse_id'] !== $original->warehouse_id
                || $input['currency'] !== $invoiceCurrency
                || $input['date'] < $original->date) {
                TradeLedger::fail('Keep the invoice customer, warehouse and currency, and use a date on or after the original invoice.');
            }
            if ($preview) {
                return $values;
            }
            $costPlan = $this->costPlan($original, $values['items']);
            $t = app(TradeLedger::class);
            $number = $original->display_number;
            $reversalId = (string) Str::uuid();
            $reversal = $t->document($user, 'invoice_reversal', ['request_id' => $reversalId, 'date' => $input['date'], 'reference' => $number, 'warehouse_id' => $original->warehouse_id, 'customer_id' => $original->customer_id, 'party_name' => $original->party_name, 'parent_id' => $id, 'notes' => 'Replaced by an edit of the same invoice', 'currency_snapshot' => $original->currency_snapshot], $original->items, $original->net, $original->tax, $original->total, $payload);
            app(StockLedger::class)->invoiceAmendmentReturn($user, ['request_id' => $reversalId, 'warehouse_id' => $original->warehouse_id, 'type' => 'credit_return', 'reference' => $number, 'reason' => 'Restore original quantities before invoice edit', 'lines' => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'unit_cost' => (string) BigDecimal::of($i['cost_value'])->dividedBy($i['quantity'], 4, RoundingMode::HalfUp), 'value_override' => $i['cost_value']], $original->items)]);
            TradeAllocation::create(['document_id' => $reversal->id, 'target_id' => $id, 'amount' => $original->total, 'original_amount' => $original->currency_snapshot['original_amount'] ?? null]);
            $ledger = app(AccountingLedger::class);
            $oldCost = PosMath::sum(array_column($original->items, 'cost_value'), 4);
            $t->post($user, $reversal, [$ledger->line('sales', $original->net), $ledger->line('tax_payable', $original->tax), $ledger->line('receivable', '0', $original->total), $ledger->line('inventory', $oldCost), $ledger->line('cogs', '0', $oldCost)]);
            app(TradeSales::class)->assertCredit($original->customer_id, $input['date'], $values['total'], $original->book);
            $stock = app(StockLedger::class)->invoiceAmendmentSale($user, ['request_id' => $input['request_id'], 'warehouse_id' => $original->warehouse_id, 'type' => 'credit_sale', 'reference' => $number, 'reason' => 'Apply edited invoice quantities', 'lines' => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => (string) BigDecimal::of($i['quantity'])->negated(), 'value_override' => $costPlan[$i['variant_id']]], $values['items'])]);
            $costs = $stock->movements->keyBy('variant_id');
            $items = array_map(fn ($i) => [...$i, 'cost_value' => (string) BigDecimal::of($costs->get($i['variant_id'])?->value ?? '0')->negated()], $values['items']);
            $edited = $t->document($user, 'invoice', ['request_id' => $input['request_id'], 'date' => $input['date'], 'due_on' => $input['due_on'], 'reference' => $input['reference'], 'notes' => $input['notes'] ?? null, 'warehouse_id' => $original->warehouse_id, 'customer_id' => $original->customer_id, 'party_name' => $original->party_name, 'parent_id' => $id, 'currency_snapshot' => $values['currency_snapshot']], $items, $values['net'], $values['tax'], $values['total'], $payload);
            $cost = PosMath::sum(array_column($items, 'cost_value'), 4);
            $t->post($user, $edited, [$ledger->line('receivable', $edited->total), $ledger->line('sales', '0', $edited->net), $ledger->line('tax_payable', '0', $edited->tax), $ledger->line('cogs', $cost), $ledger->line('inventory', '0', $cost)]);
            Audit::record('sales.invoice_edited', (string) $edited->id, ['previous_version_id' => $id, 'number' => $number]);

            return $edited;
        }, 3);
    }

    private function costPlan(TradeDocument $invoice, array $items): array
    {
        $original = collect($invoice->items)->keyBy('variant_id');
        $costs = [];
        foreach ($items as $item) {
            $variant = ProductVariant::with('product')->findOrFail($item['variant_id']);
            if ($variant->product->item_type === 'service') {
                $costs[$variant->id] = '0';

                continue;
            }
            $old = $original->get($variant->id);
            $quantity = BigDecimal::of($item['quantity']);
            $kept = $old ? ($quantity->isLessThan($old['quantity']) ? $quantity : BigDecimal::of($old['quantity'])) : BigDecimal::zero();
            $extra = $quantity->minus($kept);
            $value = $old ? BigDecimal::of(PosMath::portion($old['cost_value'], $old['quantity'], '0', (string) $kept, 4)) : BigDecimal::zero();
            if ($extra->isPositive()) {
                $balance = StockBalance::where('warehouse_id', $invoice->warehouse_id)->where('variant_id', $variant->id)->first();
                if (! $balance || $extra->isGreaterThan($balance->quantity)) {
                    TradeLedger::fail('Insufficient stock for '.$variant->sku.'. Review the edited quantity.');
                }
                $value = $value->plus($extra->isEqualTo($balance->quantity) ? $balance->value : BigDecimal::of($balance->value)->multipliedBy($extra)->dividedBy($balance->quantity, 4, RoundingMode::HalfUp));
            }
            $costs[$variant->id] = (string) $value->toScale(4);
        }

        return $costs;
    }
}

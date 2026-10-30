<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\PurchaseOrder;
use App\Models\TradeDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TradePurchasing
{
    public function receive(User $u, int $orderId, array $input): TradeDocument
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:100', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($u, $orderId, $d) {
            $t = app(TradeLedger::class);
            $t->lock($u, 'purchasing.receive');
            $o = PurchaseOrder::findOrFail($orderId);
            InventoryAccess::warehouse($u, $o->warehouse_id);
            $payload = [...$d, 'order_id' => $o->id];
            if ($old = $t->existing($d['request_id'], $payload)) {
                return $old;
            }if (! in_array($o->status, ['approved', 'partially_received'], true) || $o->book !== AccountingAccess::book() || $d['date'] < $o->date) {
                TradeLedger::fail('Order must be approved in the current book and received on or after its date.');
            }$received = [];
            foreach (TradeDocument::where('kind', 'receipt')->where('order_id', $o->id)->get() as $r) {
                foreach ($r->items as $i) {
                    $received[$i['variant_id']] = (string) BigDecimal::of($received[$i['variant_id']] ?? '0')->plus($i['quantity']);
                }
            }$ordered = collect($o->items)->keyBy('variant_id');
            $items = [];
            $net = BigDecimal::zero();
            $originalNet = BigDecimal::zero();
            foreach ($d['items'] as $i) {
                $line = $ordered->get($i['variant_id']);
                $q = BigDecimal::of($i['quantity']);
                if (! $line || ! $q->isPositive() || $q->plus($received[$i['variant_id']] ?? '0')->isGreaterThan($line['quantity'])) {
                    TradeLedger::fail('Receipt quantity exceeds the unreceived order quantity.');
                }$value = $q->multipliedBy($line['unit_cost'])->toScale(4, RoundingMode::HalfUp);
                $item = [...$line, 'quantity' => (string) $q->toScale(3), 'value' => (string) $value];
                if (isset($line['original_unit_cost'])) {
                    $originalValue = $q->multipliedBy($line['original_unit_cost'])->toScale(4, RoundingMode::HalfUp);
                    $item['original_value'] = (string) $originalValue;
                    $item['original'] = ['unit_cost' => $line['original_unit_cost'], 'value' => (string) $originalValue];
                    $originalNet = $originalNet->plus($originalValue);
                }
                $items[] = $item;
                $net = $net->plus($value);
                $received[$i['variant_id']] = (string) $q->plus($received[$i['variant_id']] ?? '0');
            }
            $receiptMinorUnits = $o->currency_snapshot ? (int) Currency::where('code', $o->currency_snapshot['currency'])->value('minor_units') : 4;
            $receiptSnapshot = $o->currency_snapshot ? [...$o->currency_snapshot, 'original_amount' => (string) $originalNet->toScale($receiptMinorUnits, RoundingMode::HalfUp), 'base_amount' => (string) $net] : null;
            $doc = $t->document($u, 'receipt', [...collect($d)->except('items')->all(), 'warehouse_id' => $o->warehouse_id, 'supplier_id' => $o->supplier_id, 'party_name' => $o->supplier_name, 'order_id' => $o->id, 'currency_snapshot' => $receiptSnapshot], $items, (string) $net, '0', (string) $net, $payload);
            app(StockLedger::class)->trade($u, ['request_id' => $d['request_id'], 'warehouse_id' => $o->warehouse_id, 'type' => 'goods_receipt', 'reference' => $doc->number, 'reason' => 'Receipt against '.$o->number, 'lines' => array_map(fn ($i) => collect($i)->only('variant_id', 'quantity', 'unit_cost')->all(), $items)]);
            $l = app(AccountingLedger::class);
            $t->post($u, $doc, [$l->line('inventory', (string) $net), $l->line($t->accrual()->id, '0', (string) $net)]);
            $complete = true;
            foreach ($o->items as $i) {
                if (BigDecimal::of($received[$i['variant_id']] ?? '0')->isLessThan($i['quantity'])) {
                    $complete = false;
                }
            }$o->update(['status' => $complete ? 'completed' : 'partially_received']);

            return $doc;
        }, 3);
    }

    public function bill(User $u, int $receiptId, array $input): TradeDocument
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:date', 'reference' => 'required|string|max:100', 'tax_rate' => ['required', 'string', 'numeric', 'between:0,100', 'decimal:0,4'], 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($u, $receiptId, $d) {
            $t = app(TradeLedger::class);
            $t->lock($u, 'purchasing.bill');
            $r = TradeDocument::where('kind', 'receipt')->findOrFail($receiptId);
            InventoryAccess::warehouse($u, $r->warehouse_id);
            $payload = [...$d, 'receipt_id' => $r->id];
            if ($old = $t->existing($d['request_id'], $payload)) {
                return $old;
            }if ($r->book !== AccountingAccess::book() || $d['date'] < $r->date) {
                TradeLedger::fail('Bill must follow the receipt in its operating book.');
            }if (TradeDocument::where(['kind' => 'bill', 'book' => $r->book, 'supplier_id' => $r->supplier_id, 'reference' => $d['reference']])->exists()) {
                TradeLedger::fail('Supplier invoice already posted.');
            }$billed = [];
            foreach (TradeDocument::where('kind', 'bill')->where('parent_id', $r->id)->get() as $b) {
                foreach ($b->items as $i) {
                    $billed[$i['variant_id']] = (string) BigDecimal::of($billed[$i['variant_id']] ?? '0')->plus($i['quantity']);
                }
            }$received = collect($r->items)->keyBy('variant_id');
            $items = [];
            $net = BigDecimal::zero();
            $receiptNet = BigDecimal::zero();
            $originalNet = BigDecimal::zero();
            foreach ($d['items'] as $i) {
                $line = $received->get($i['variant_id']);
                $q = BigDecimal::of($i['quantity']);
                $prior = $billed[$i['variant_id']] ?? '0';
                if (! $line || ! $q->isPositive() || $q->plus($prior)->isGreaterThan($line['quantity'])) {
                    TradeLedger::fail('Bill quantity exceeds the unbilled receipt quantity.');
                }
                $receiptValue = PosMath::portion($line['value'], $line['quantity'], $prior, (string) $q, 4);
                $receiptNet = $receiptNet->plus($receiptValue);
                $item = [...$line, 'quantity' => (string) $q->toScale(3), 'value' => $receiptValue, 'tax_rate' => $d['tax_rate']];
                if (isset($line['original_value'])) {
                    $originalValue = PosMath::portion($line['original_value'], $line['quantity'], $prior, (string) $q, 4);
                    $originalTax = BigDecimal::of($originalValue)->multipliedBy($d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
                    $item['original_value'] = $originalValue;
                    $item['net'] = $originalValue;
                    $item['tax'] = (string) $originalTax;
                    $item['total'] = (string) BigDecimal::of($originalValue)->plus($originalTax);
                    $originalNet = $originalNet->plus($originalValue);
                } else {
                    $item['net'] = $receiptValue;
                    $item['tax'] = (string) BigDecimal::of($receiptValue)->multipliedBy($d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
                    $item['total'] = (string) BigDecimal::of($item['net'])->plus($item['tax']);
                }
                $items[] = $item;
                $net = $net->plus($item['net']);
            }
            $billSnapshot = null;
            if ($r->currency_snapshot) {
                $originalTax = $originalNet->multipliedBy($d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
                $priorOriginal = '0';
                foreach ($items as &$item) {
                    $lineTax = PosMath::portion((string) $originalTax, (string) $originalNet, $priorOriginal, $item['net'], 2);
                    $item['tax'] = $lineTax;
                    $item['total'] = (string) BigDecimal::of($item['net'])->plus($lineTax);
                    $priorOriginal = (string) BigDecimal::of($priorOriginal)->plus($item['net']);
                }
                unset($item);
                $converted = app(TradeCurrency::class)->convert($items, $r->currency_snapshot['currency'], $d['date']);
                $items = $converted['items'];
                $billSnapshot = $converted['snapshot'];
                $net = BigDecimal::of(PosMath::sum(array_column($items, 'net')));
                $tax = BigDecimal::of(PosMath::sum(array_column($items, 'tax')));
            } else {
                $net = $receiptNet;
                $tax = $net->multipliedBy($d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
            }
            $total = $net->plus($tax)->toScale(2, RoundingMode::HalfUp);
            $rounding = $total->minus($net)->minus($tax);
            $doc = $t->document($u, 'bill', [...collect($d)->except('items', 'tax_rate')->all(), 'warehouse_id' => $r->warehouse_id, 'supplier_id' => $r->supplier_id, 'party_name' => $r->party_name, 'parent_id' => $r->id, 'order_id' => $r->order_id, 'currency_snapshot' => $billSnapshot], $items, (string) $net, (string) $tax, (string) $total, $payload);
            $l = app(AccountingLedger::class);
            $variance = $net->minus($receiptNet)->toScale(2, RoundingMode::HalfUp);
            $posting = [$l->line($t->accrual()->id, (string) $receiptNet), $l->line('tax_receivable', (string) $tax), $l->line('payable', '0', (string) $total)];
            if ($variance->isPositive()) {
                $posting[] = $l->line(app(ForeignLedger::class)->mapping('fx_loss'), (string) $variance);
            } elseif ($variance->isNegative()) {
                $posting[] = $l->line(app(ForeignLedger::class)->mapping('fx_gain'), '0', (string) $variance->abs());
            }
            if ($rounding->isPositive()) {
                $posting[] = $l->line('rounding', (string) $rounding);
            } elseif ($rounding->isNegative()) {
                $posting[] = $l->line('rounding', '0', (string) $rounding->abs());
            }
            $t->post($u, $doc, $posting);

            return $doc;
        }, 3);
    }
}

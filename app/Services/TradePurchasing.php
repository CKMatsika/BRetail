<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
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
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:100', 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'nullable|integer', 'items.*.account_id' => 'nullable|integer', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

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
            }
            $lineKey = fn ($i) => ! empty($i['variant_id']) ? 'v_'.$i['variant_id'] : (! empty($i['account_id']) ? 'a_'.$i['account_id'] : '');
            $received = [];
            foreach (TradeDocument::where('kind', 'receipt')->where('order_id', $o->id)->get() as $r) {
                foreach ($r->items as $i) {
                    $k = $lineKey($i);
                    $received[$k] = (string) BigDecimal::of($received[$k] ?? '0')->plus($i['quantity']);
                }
            }$ordered = collect($o->items)->keyBy($lineKey);
            $items = [];
            $net = BigDecimal::zero();
            $originalNet = BigDecimal::zero();
            foreach ($d['items'] as $i) {
                $k = $lineKey($i);
                $line = $ordered->get($k);
                $q = BigDecimal::of($i['quantity']);
                if (! $line || ! $q->isPositive() || $q->plus($received[$k] ?? '0')->isGreaterThan($line['quantity'])) {
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
                $received[$k] = (string) $q->plus($received[$k] ?? '0');
            }
            $receiptMinorUnits = $o->currency_snapshot ? (int) Currency::where('code', $o->currency_snapshot['currency'])->value('minor_units') : 4;
            $receiptSnapshot = $o->currency_snapshot ? [...$o->currency_snapshot, 'original_amount' => (string) $originalNet->toScale($receiptMinorUnits, RoundingMode::HalfUp), 'base_amount' => (string) $net] : null;
            $doc = $t->document($u, 'receipt', [...collect($d)->except('items')->all(), 'warehouse_id' => $o->warehouse_id, 'supplier_id' => $o->supplier_id, 'party_name' => $o->supplier_name, 'order_id' => $o->id, 'currency_snapshot' => $receiptSnapshot], $items, (string) $net, '0', (string) $net, $payload);
            
            $stockLines = array_values(array_filter($items, fn ($i) => ! empty($i['variant_id'])));
            if (count($stockLines) > 0) {
                app(StockLedger::class)->trade($u, ['request_id' => $d['request_id'], 'warehouse_id' => $o->warehouse_id, 'type' => 'goods_receipt', 'reference' => $doc->number, 'reason' => 'Receipt against '.$o->number, 'lines' => array_map(fn ($i) => collect($i)->only('variant_id', 'quantity', 'unit_cost')->all(), $stockLines)]);
            }
            $l = app(AccountingLedger::class);
            $stockNet = collect($stockLines)->reduce(fn ($sum, $i) => $sum->plus($i['value']), BigDecimal::zero());
            $posting = [];
            if ($stockNet->isPositive()) {
                $posting[] = $l->line('inventory', (string) $stockNet);
            }
            $accountSums = [];
            foreach ($items as $i) {
                if (! empty($i['account_id'])) {
                    $accountSums[$i['account_id']] = ($accountSums[$i['account_id']] ?? BigDecimal::zero())->plus($i['value']);
                }
            }
            foreach ($accountSums as $accId => $accVal) {
                $posting[] = $l->line((int) $accId, (string) $accVal);
            }
            $posting[] = $l->line($t->accrual()->id, '0', (string) $net);
            $t->post($u, $doc, $posting);

            $complete = true;
            foreach ($o->items as $i) {
                $k = $lineKey($i);
                if (BigDecimal::of($received[$k] ?? '0')->isLessThan($i['quantity'])) {
                    $complete = false;
                }
            }$o->update(['status' => $complete ? 'completed' : 'partially_received']);

            return $doc;
        }, 3);
    }

    public function bill(User $u, int $receiptId, array $input): TradeDocument
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:date', 'reference' => 'required|string|max:100', 'tax_rate' => ['required', 'string', 'numeric', 'between:0,100', 'decimal:0,4'], 'items' => 'required|array|min:1|max:500', 'items.*.variant_id' => 'nullable|integer', 'items.*.account_id' => 'nullable|integer', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/']])->validate();

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
            }
            $lineKey = fn ($i) => ! empty($i['variant_id']) ? 'v_'.$i['variant_id'] : (! empty($i['account_id']) ? 'a_'.$i['account_id'] : '');
            $billed = [];
            foreach (TradeDocument::where('kind', 'bill')->where('parent_id', $r->id)->get() as $b) {
                foreach ($b->items as $i) {
                    $k = $lineKey($i);
                    $billed[$k] = (string) BigDecimal::of($billed[$k] ?? '0')->plus($i['quantity']);
                }
            }$received = collect($r->items)->keyBy($lineKey);
            $items = [];
            $net = BigDecimal::zero();
            $receiptNet = BigDecimal::zero();
            $originalNet = BigDecimal::zero();
            foreach ($d['items'] as $i) {
                $k = $lineKey($i);
                $line = $received->get($k);
                $q = BigDecimal::of($i['quantity']);
                $prior = $billed[$k] ?? '0';
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

    public function directBill(User $u, array $input): TradeDocument
    {
        $d = Validator::make($input, [
            'request_id' => 'required|uuid',
            'warehouse_id' => 'required|integer',
            'supplier_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'due_on' => 'required|date_format:Y-m-d|after_or_equal:date',
            'reference' => 'required|string|max:100',
            'tax_rate' => ['required', 'string', 'numeric', 'between:0,100', 'decimal:0,4'],
            'items' => 'required|array|min:1|max:500',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.account_id' => 'nullable|integer',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'items.*.unit_cost' => ['required', 'string', Catalog::MONEY],
        ])->validate();

        return DB::transaction(function () use ($u, $d) {
            $t = app(TradeLedger::class);
            $t->lock($u, 'purchasing.bill');
            $supplier = Supplier::findOrFail($d['supplier_id']);
            if (! $supplier->active) {
                TradeLedger::fail('Supplier is inactive.');
            }
            InventoryAccess::warehouse($u, $d['warehouse_id']);
            $book = AccountingAccess::book();
            if ($old = $t->existing($d['request_id'], $d)) {
                return $old;
            }
            if (TradeDocument::where(['kind' => 'bill', 'book' => $book, 'supplier_id' => $supplier->id, 'reference' => $d['reference']])->exists()) {
                TradeLedger::fail('Supplier invoice already posted.');
            }

            $items = [];
            $net = BigDecimal::zero();
            $accountSums = [];
            $stockLines = [];

            foreach ($d['items'] as $i) {
                if (empty($i['variant_id']) && empty($i['account_id'])) {
                    TradeLedger::fail('Each line must have either a product variant or a ledger account.');
                }
                $q = BigDecimal::of($i['quantity'])->toScale(3);
                if (! $q->isPositive()) {
                    TradeLedger::fail('Quantities must be positive.');
                }
                $unitCost = BigDecimal::of($i['unit_cost'])->toScale(4);
                $lineValue = $q->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);
                $net = $net->plus($lineValue);

                if (! empty($i['account_id'])) {
                    $acc = ChartAccount::where('active', true)->findOrFail($i['account_id']);
                    if ($acc->is_cash) {
                        TradeLedger::fail('Cannot use cash accounts as expense lines.');
                    }
                    $item = [
                        'line_type' => 'account',
                        'account_id' => $acc->id,
                        'variant_id' => null,
                        'sku' => $acc->code,
                        'description' => $i['description'] ?? ($acc->code.' '.$acc->name),
                        'quantity' => (string) $q,
                        'unit_cost' => (string) $unitCost,
                        'value' => (string) $lineValue,
                        'net' => (string) $lineValue,
                    ];
                    $accountSums[$acc->id] = ($accountSums[$acc->id] ?? BigDecimal::zero())->plus($lineValue);
                } else {
                    $v = ProductVariant::with('product')->findOrFail($i['variant_id']);
                    $item = [
                        'line_type' => 'stock',
                        'variant_id' => $v->id,
                        'account_id' => null,
                        'sku' => $v->sku,
                        'description' => $v->product->name.' / '.$v->name,
                        'quantity' => (string) $q,
                        'unit_cost' => (string) $unitCost,
                        'value' => (string) $lineValue,
                        'net' => (string) $lineValue,
                    ];
                    $stockLines[] = $item;
                }
                $items[] = $item;
            }

            $tax = $net->multipliedBy($d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
            $total = $net->plus($tax)->toScale(2, RoundingMode::HalfUp);
            $rounding = $total->minus($net)->minus($tax);

            $doc = $t->document($u, 'bill', [
                'request_id' => $d['request_id'],
                'date' => $d['date'],
                'due_on' => $d['due_on'],
                'reference' => $d['reference'],
                'warehouse_id' => $d['warehouse_id'],
                'supplier_id' => $supplier->id,
                'party_name' => $supplier->name,
                'currency_snapshot' => null,
            ], $items, (string) $net, (string) $tax, (string) $total, $d);

            if (count($stockLines) > 0) {
                app(StockLedger::class)->trade($u, [
                    'request_id' => $d['request_id'],
                    'warehouse_id' => $d['warehouse_id'],
                    'type' => 'goods_receipt',
                    'reference' => $doc->number,
                    'reason' => 'Direct invoice from '.$supplier->name,
                    'lines' => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'unit_cost' => $i['unit_cost']], $stockLines),
                ]);
            }

            $l = app(AccountingLedger::class);
            $posting = [];
            $stockNet = collect($stockLines)->reduce(fn ($sum, $i) => $sum->plus($i['value']), BigDecimal::zero());
            if ($stockNet->isPositive()) {
                $posting[] = $l->line('inventory', (string) $stockNet);
            }
            foreach ($accountSums as $accId => $val) {
                $posting[] = $l->line((int) $accId, (string) $val);
            }
            $posting[] = $l->line('tax_receivable', (string) $tax);
            $posting[] = $l->line('payable', '0', (string) $total);
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

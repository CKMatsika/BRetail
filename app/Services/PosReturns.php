<?php

namespace App\Services;

use App\Models\PosCurrencyOperation;
use App\Models\PosRefundPayment;
use App\Models\PosReturn;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PosReturns
{
    private function lines(array $input): array
    {
        $d = Validator::make($input, ['lines' => 'required|array|min:1|max:200', 'lines.*.sale_item_id' => 'required|integer|distinct', 'lines.*.quantity' => ['required', 'string', Catalog::QUANTITY]])->validate();
        $lines = array_map(fn ($l) => ['sale_item_id' => (int) $l['sale_item_id'], 'quantity' => (string) BigDecimal::of($l['quantity'])->toScale(3)], $d['lines']);
        usort($lines, fn ($a, $b) => $a['sale_item_id'] <=> $b['sale_item_id']);

        return $lines;
    }

    public function quote(User $user, PosSale $sale, array $input): array
    {
        InventoryAccess::permit($user, 'pos.refund');
        app(PosAccess::class)->sale($user, $sale->id);
        $lines = $this->lines($input);
        $items = [];
        $returnIds = PosReturn::where('sale_id', $sale->id)->pluck('id');
        foreach ($lines as $l) {
            $item = PosSaleItem::where('sale_id', $sale->id)->findOrFail($l['sale_item_id']);
            $q = BigDecimal::of($l['quantity']);
            $prior = PosMath::sum(PosReturnItem::whereIn('return_id', $returnIds)->where('sale_item_id', $item->id)->get()->pluck('quantity'), 3);
            if (! $q->isPositive() || $q->plus($prior)->isGreaterThan($item->quantity)) {
                throw ValidationException::withMessages(['lines' => 'Returned quantities exceed the remaining sold quantities.']);
            }
            $net = PosMath::portion($item->net, $item->quantity, $prior, $l['quantity']);
            $tax = PosMath::portion($item->tax, $item->quantity, $prior, $l['quantity']);
            $cost = PosMath::portion($item->cost_value, $item->quantity, $prior, $l['quantity'], 4);
            $items[] = [...$l, 'variant_id' => $item->variant_id, 'description' => $item->description, 'sku' => $item->sku, 'net' => $net, 'tax' => $tax, 'total' => PosMath::money(BigDecimal::of($net)->plus($tax)), 'cost_value' => $cost];
        }
        $result = ['sale_id' => $sale->id, 'currency' => $sale->currency, 'items' => $items, 'net' => PosMath::sum(array_column($items, 'net')), 'tax' => PosMath::sum(array_column($items, 'tax')), 'total' => PosMath::sum(array_column($items, 'total'))];
        $result['quote_token'] = PosMath::hash($result);

        return $result;
    }

    public function post(User $user, PosSale $sale, array $input, bool $currencyRefund = false, string $foreignCashAllowance = '0'): PosReturn
    {
        InventoryAccess::permit($user, 'pos.refund');
        if (! $currencyRefund && PosCurrencyOperation::where('sale_id', $sale->id)->where('kind', 'sale')->exists()) {
            throw ValidationException::withMessages(['payments' => 'Use the original-currency refund workflow for this receipt.']);
        }
        $mode = app(PosAccountingGate::class)->mode();
        app(PosAccess::class)->sale($user, $sale->id);
        $lines = $this->lines($input);
        $meta = Validator::make($input, ['request_id' => 'required|uuid', 'session_id' => 'required|integer', 'reason' => 'required|string|max:2000', 'quote_token' => 'required|string|size:64', 'payments' => 'present|array'])->validate();
        $payments = $meta['payments'] ? app(PosCart::class)->payments($meta['payments']) : [];
        $hash = PosMath::hash(['sale_id' => $sale->id, 'session_id' => (int) $meta['session_id'], 'user_id' => $user->id, 'reason' => $meta['reason'], 'quote_token' => $meta['quote_token'], 'lines' => $lines, 'payments' => $payments]);

        return DB::transaction(function () use ($user, $sale, $lines, $meta, $payments, $hash, $mode, $foreignCashAllowance) {
            app(PosAccess::class)->lock($user, 'pos.refund');
            $existing = PosReturn::where('request_id', $meta['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->sale_id === $sale->id, 403);
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Return request ID conflict.']);
                }

                return $existing->load('items', 'payments');
            }
            $session = app(PosShifts::class)->own($user, $meta['session_id']);
            if ($session->warehouse_id !== $sale->warehouse_id || $session->currency !== $sale->currency) {
                throw ValidationException::withMessages(['session' => 'Returns require your own open shift in the original warehouse and currency.']);
            }
            $quote = $this->quote($user, $sale, ['lines' => $lines]);
            if (! hash_equals($quote['quote_token'], $meta['quote_token'])) {
                throw ValidationException::withMessages(['quote' => 'Return quantities changed. Review a fresh return quote.']);
            }
            if (! BigDecimal::of(PosMath::sum(array_column($payments, 'amount')))->isEqualTo($quote['total'])) {
                throw ValidationException::withMessages(['payments' => 'Refund payments must equal the return total exactly.']);
            }
            $detail = app(PosAccess::class)->detail($sale);
            foreach ($payments as $p) {
                if (BigDecimal::of($p['amount'])->isGreaterThan($detail['remaining_payments'][$p['method']] ?? '0')) {
                    throw ValidationException::withMessages(['payments' => 'A refund cannot exceed the remaining original payment for that method.']);
                }if ($p['method'] === 'cash' && BigDecimal::of($p['amount'])->isGreaterThan(BigDecimal::of(app(PosShifts::class)->summary($session)['expected_cash'])->plus($foreignCashAllowance))) {
                    throw ValidationException::withMessages(['payments' => 'This drawer has insufficient expected cash for the refund.']);
                }
            }
            $sequence = (PosReturn::max('sequence') ?? 0) + 1;
            $number = 'RET-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $return = PosReturn::create(['sale_id' => $sale->id, 'session_id' => $session->id, 'user_id' => $user->id, 'warehouse_id' => $sale->warehouse_id, 'request_id' => $meta['request_id'], 'payload_hash' => $hash, 'sequence' => $sequence, 'receipt_number' => $number, 'reason' => $meta['reason'], 'currency' => $sale->currency, 'mode' => $mode, 'net' => $quote['net'], 'tax' => $quote['tax'], 'total' => $quote['total'], 'created_at' => now()]);
            $stock = [];
            foreach ($quote['items'] as $item) {
                $record = collect($item)->except('description', 'sku')->all();
                unset($record['sale_item_id']);
                PosReturnItem::create([...$record, 'return_id' => $return->id, 'sale_item_id' => $item['sale_item_id']]);
                $stock[] = ['variant_id' => $item['variant_id'], 'quantity' => $item['quantity'], 'unit_cost' => (string) BigDecimal::of($item['cost_value'])->dividedBy($item['quantity'], 4, RoundingMode::HalfUp), 'value_override' => $item['cost_value']];
            }
            app(StockLedger::class)->customerReturn($user, ['request_id' => $meta['request_id'], 'warehouse_id' => $sale->warehouse_id, 'type' => 'customer_return', 'reference' => $number, 'reason' => $meta['reason'], 'lines' => $stock]);
            foreach ($payments as $p) {
                PosRefundPayment::create([...$p, 'return_id' => $return->id]);
            }
            abort_unless($session->mode === $mode && $sale->mode === $mode && app(PosAccountingGate::class)->mode() === $mode, 422, 'Sale and shift operating modes differ.');
            app(AccountingBridge::class)->refund($user, $return);
            Audit::record('pos.return_completed', (string) $return->id, ['sale_id' => $sale->id, 'total' => $return->total, 'mode' => $mode]);

            return $return->load('items', 'payments');
        }, 3);
    }
}

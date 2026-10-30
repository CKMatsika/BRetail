<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosHold;
use App\Models\PosPayment;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PosSales
{
    public function checkout(User $user, array $input): PosSale
    {
        InventoryAccess::permit($user, 'pos.sell');
        $mode = app(PosAccountingGate::class)->mode();
        $base = app(PosCart::class)->validate($input);
        $meta = Validator::make($input, ['request_id' => 'required|uuid', 'session_id' => 'required|integer', 'quote_token' => 'required|string|size:64', 'hold_id' => 'nullable|integer', 'payments' => 'required|array'])->validate();
        $payments = app(PosCart::class)->payments($meta['payments']);
        $payload = [...$base, 'session_id' => (int) $meta['session_id'], 'quote_token' => $meta['quote_token'], 'hold_id' => isset($meta['hold_id']) ? (int) $meta['hold_id'] : null, 'payments' => $payments, 'user_id' => $user->id];
        $hash = PosMath::hash($payload);

        return DB::transaction(function () use ($user, $base, $meta, $payments, $payload, $hash, $mode) {
            app(PosAccess::class)->lock($user, 'pos.sell');
            InventoryAccess::warehouse($user, $base['warehouse_id']);
            $existing = PosSale::where('request_id', $meta['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 403);
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Sale request ID conflict.']);
                }

                return $existing->load('items', 'payments');
            }
            $session = app(PosShifts::class)->own($user, $meta['session_id']);
            if ($session->warehouse_id !== $base['warehouse_id']) {
                throw ValidationException::withMessages(['session' => 'The shift and cart must use the same warehouse.']);
            }
            $quote = app(PosCart::class)->quote($user, $base);
            if (! hash_equals($quote['quote_token'], $meta['quote_token'])) {
                throw ValidationException::withMessages(['quote' => 'Prices or tax changed. Review the updated total before payment.']);
            }if (! BigDecimal::of($quote['total'])->isPositive()) {
                throw ValidationException::withMessages(['payments' => 'A sale total must be positive.']);
            }if ($quote['currency'] !== $session->currency) {
                throw ValidationException::withMessages(['currency' => 'Currency changed during this shift. Close it before continuing.']);
            }
            $tender = BigDecimal::of(PosMath::sum(array_column($payments, 'amount')));
            $change = $tender->minus($quote['total']);
            $cash = collect($payments)->firstWhere('method', 'cash');
            if ($change->isNegative() || ($change->isPositive() && (! $cash || $change->isGreaterThan($cash['amount'])))) {
                throw ValidationException::withMessages(['payments' => 'Payments must cover the total. Only cash can produce change.']);
            }
            if ($payload['hold_id']) {
                $hold = PosHold::findOrFail($payload['hold_id']);
                abort_unless($hold->user_id === $user->id && $hold->warehouse_id === $base['warehouse_id'], 403);
                if ($hold->status !== 'held') {
                    throw ValidationException::withMessages(['hold' => 'This held cart was already consumed or cancelled.']);
                }$hold->update(['status' => 'consumed']);
            }
            $company = Company::firstOrFail();
            $customer = $base['customer_id'] ? PosCustomer::findOrFail($base['customer_id']) : null;
            $sequence = (PosSale::max('sequence') ?? 0) + 1;
            $number = ($company->settings['invoice_prefix'] ?? 'INV').'-POS-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $sale = PosSale::create(['session_id' => $session->id, 'warehouse_id' => $session->warehouse_id, 'user_id' => $user->id, 'customer_id' => $customer?->id, 'hold_id' => $payload['hold_id'], 'request_id' => $meta['request_id'], 'payload_hash' => $hash, 'sequence' => $sequence, 'receipt_number' => $number, 'currency' => $quote['currency'], 'mode' => $mode, 'company_snapshot' => ['name' => $company->name, 'address' => $company->settings['address'] ?? '', 'phone' => $company->settings['phone'] ?? '', 'receipt_footer' => $company->settings['receipt_footer'] ?? 'Thank you for shopping with us.'], 'customer_name' => $customer?->name, 'cashier_name' => $user->name, 'warehouse_name' => InventoryAccess::warehouse($user, $session->warehouse_id)->name, 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'tax' => $quote['tax'], 'total' => $quote['total'], 'change' => PosMath::money($change), 'created_at' => now()]);
            $stock = app(StockLedger::class)->sale($user, ['request_id' => $meta['request_id'], 'warehouse_id' => $session->warehouse_id, 'type' => 'sale', 'reference' => $number, 'reason' => $mode === 'live' ? 'POS sale' : 'Training POS sale', 'lines' => array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => (string) BigDecimal::of($l['quantity'])->negated()], $quote['items'])]);
            $costs = $stock->movements->keyBy('variant_id');
            foreach ($quote['items'] as $item) {
                PosSaleItem::create([...$item, 'sale_id' => $sale->id, 'cost_value' => (string) BigDecimal::of($costs[$item['variant_id']]->value)->negated()]);
            }
            foreach ($payments as $p) {
                PosPayment::create(['sale_id' => $sale->id, 'method' => $p['method'], 'amount' => PosMath::money(BigDecimal::of($p['amount'])->minus($p['method'] === 'cash' ? $change : '0')), 'tendered' => $p['amount'], 'change' => $p['method'] === 'cash' ? PosMath::money($change) : '0.00', 'reference' => $p['reference']]);
            }
            app(LoyaltyService::class)->redeemForSale($user, $sale);
            abort_unless($session->mode === $mode && app(PosAccountingGate::class)->mode() === $mode, 422, 'Shift operating mode changed.');
            app(AccountingBridge::class)->sale($user, $sale);
            app(LoyaltyService::class)->earnForSale($user, $sale);
            Audit::record('pos.sale_completed', (string) $sale->id, ['receipt' => $number, 'total' => $sale->total, 'mode' => $mode]);

            return $sale->load('items', 'payments');
        }, 3);
    }
}

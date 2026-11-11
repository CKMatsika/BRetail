<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PosCart
{
    public function validate(array $input): array
    {
        $d = Validator::make($input, ['warehouse_id' => 'required|integer', 'customer_id' => 'nullable|integer', 'lines' => 'required|array|min:1|max:200', 'lines.*.variant_id' => 'required|integer|distinct', 'lines.*.quantity' => ['required', 'string', Catalog::QUANTITY], 'lines.*.discount_percent' => ['sometimes', 'required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/']])->validate();
        $d['warehouse_id'] = (int) $d['warehouse_id'];
        $d['customer_id'] = isset($d['customer_id']) ? (int) $d['customer_id'] : null;
        $d['lines'] = array_map(fn ($l) => ['variant_id' => (int) $l['variant_id'], 'quantity' => (string) BigDecimal::of($l['quantity'])->toScale(3), 'discount_percent' => (string) BigDecimal::of($l['discount_percent'] ?? '0')->toScale(2)], $d['lines']);
        usort($d['lines'], fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']);

        return $d;
    }

    public function quote(User $user, array $input): array
    {
        InventoryAccess::permit($user, 'pos.sell');
        $d = $this->validate($input);
        InventoryAccess::warehouse($user, $d['warehouse_id']);
        if ($d['customer_id']) {
            PosCustomer::findOrFail($d['customer_id']);
        }
        $company = Company::firstOrFail();
        $items = [];
        $rate = (string) ($company->settings['tax_rate'] ?? '0');
        if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) || BigDecimal::of($rate)->isGreaterThan('100')) {
            throw ValidationException::withMessages(['tax' => 'The company tax rate must be configured before checkout.']);
        }
        foreach ($d['lines'] as $line) {
            $v = ProductVariant::with('product')->findOrFail($line['variant_id']);
            abort_unless($v->active && $v->product->active, 422, 'This product is inactive.');
            $quantity = BigDecimal::of($line['quantity']);
            $discount = BigDecimal::of($line['discount_percent']);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['lines' => 'Sale quantities must be positive.']);
            }if ($discount->isGreaterThan('100') || (! $user->canDo('pos.discount.override') && $discount->isGreaterThan(config('pos.cashier_discount_limit')))) {
                throw ValidationException::withMessages(['discount' => 'This discount requires a manager with discount override permission.']);
            }
            $taxRate = TaxRateResolver::forClass($company, $v->product->tax_class);
            $values = PosMath::line($line['quantity'], $v->selling_price, $line['discount_percent'], $taxRate);
            $balance = StockBalance::where('warehouse_id', $d['warehouse_id'])->where('variant_id', $v->id)->first();
            if ($v->product->item_type !== 'service' && $quantity->isGreaterThan($balance?->quantity ?? '0')) {
                throw ValidationException::withMessages(['stock' => 'Insufficient stock for '.$v->sku.'. Review the quantity.']);
            }
            $items[] = [...$line, 'description' => mb_substr($v->product->name.' — '.$v->name, 0, 255), 'sku' => $v->sku, 'unit_price' => $v->selling_price, 'tax_rate' => (string) BigDecimal::of($taxRate)->toScale(4), ...$values];
        }
        $quote = ['currency' => $company->base_currency, 'items' => $items, 'subtotal' => PosMath::sum(array_column($items, 'subtotal')), 'discount' => PosMath::sum(array_column($items, 'discount')), 'tax' => PosMath::sum(array_column($items, 'tax')), 'total' => PosMath::sum(array_column($items, 'total'))];
        $quote['quote_token'] = PosMath::hash($quote);

        return $quote;
    }

    public function payments(array $input): array
    {
        $d = Validator::make(['payments' => $input], ['payments' => 'required|array|min:1|max:4', 'payments.*.method' => ['required', Rule::in(config('pos.methods')), 'distinct'], 'payments.*.amount' => ['required', 'string', PosMath::MONEY], 'payments.*.reference' => 'nullable|string|max:160'])->validate();
        $rows = [];
        foreach ($d['payments'] as $p) {
            if (! BigDecimal::of($p['amount'])->isPositive()) {
                throw ValidationException::withMessages(['payments' => 'Every tender must be positive.']);
            }if (! in_array($p['method'], ['cash', 'loyalty_points'], true) && empty($p['reference'])) {
                throw ValidationException::withMessages(['payments' => 'Noncash payments require an external reference.']);
            }$rows[] = ['method' => $p['method'], 'amount' => PosMath::money($p['amount']), 'reference' => $p['reference'] ?? null];
        }usort($rows, fn ($a, $b) => strcmp($a['method'], $b['method']));

        return $rows;
    }
}

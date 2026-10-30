<?php

namespace App\Services;

use App\Models\AccountingPurchase;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingPurchases
{
    public function post(User $u, array $input): AccountingPurchase
    {
        AccountingAccess::permit($u, 'accounting.purchase');
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'warehouse_id' => 'required|integer', 'supplier_name' => 'required|string|max:160', 'invoice_number' => 'required|string|max:100', 'date' => 'required|date_format:Y-m-d', 'settlement' => ['required', Rule::in(['payable', 'cash_safe', 'bank', 'card', 'mobile_money'])], 'tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'], 'items' => 'required|array|min:1|max:2000', 'items.*.variant_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'], 'items.*.unit_cost' => ['required', 'string', Catalog::MONEY]])->validate();
        $hash = PosMath::hash($d);
        if ($d['date'] > AccountingLedger::today()) {
            throw ValidationException::withMessages(['date' => 'Received inventory cannot use a future posting date.']);
        }

        return DB::transaction(function () use ($u, $d, $hash) {
            AccountingAccess::lock($u, 'accounting.purchase');
            $w = InventoryAccess::warehouse($u, $d['warehouse_id']);
            $book = AccountingAccess::book();
            if ($old = AccountingPurchase::where('request_id', $d['request_id'])->first()) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Purchase request conflict.']);
                }

                return $old;
            }
            if (AccountingPurchase::where(['book' => $book, 'supplier_name' => $d['supplier_name'], 'invoice_number' => $d['invoice_number']])->exists()) {
                throw ValidationException::withMessages(['invoice_number' => 'This supplier invoice is already posted.']);
            }
            $net = BigDecimal::zero();
            foreach ($d['items'] as $item) {
                if (! BigDecimal::of($item['quantity'])->isPositive()) {
                    throw ValidationException::withMessages(['items' => 'Purchase quantities must be positive.']);
                }$net = $net->plus(BigDecimal::of($item['quantity'])->multipliedBy($item['unit_cost'])->toScale(4, RoundingMode::HalfUp));
            }$tax = $net->multipliedBy((string) $d['tax_rate'])->dividedBy('100', 2, RoundingMode::HalfUp);
            $total = $net->plus($tax)->toScale(2, RoundingMode::HalfUp);
            $rounding = $total->minus($net)->minus($tax);
            $l = app(AccountingLedger::class);
            if ($d['settlement'] !== 'payable' && BigDecimal::of($l->balance($l->account($d['settlement'])->id, $book, $w->branch_id, $d['date']))->isLessThan($total)) {
                throw ValidationException::withMessages(['settlement' => 'Insufficient settlement funds.']);
            }
            $p = AccountingPurchase::create([...collect($d)->except('tax_rate')->all(), 'book' => $book, 'actor_id' => $u->id, 'payload_hash' => $hash, 'net' => (string) $net->toScale(4), 'tax' => (string) $tax, 'total' => (string) $total, 'created_at' => now()]);
            app(StockLedger::class)->purchase($u, ['request_id' => $d['request_id'], 'warehouse_id' => $w->id, 'type' => 'purchase', 'reference' => $d['invoice_number'], 'reason' => 'Purchase from '.$d['supplier_name'], 'lines' => $d['items']]);
            $lines = [$l->line('inventory', (string) $net), $l->line('tax_receivable', (string) $tax), $l->line($d['settlement'], '0', (string) $total), $rounding->isNegative() ? $l->line('rounding', '0', (string) $rounding->abs()) : $l->line('rounding', (string) $rounding)];
            $l->system($u, ['book' => $book, 'branch_id' => $w->branch_id, 'date' => $d['date'], 'reference' => $d['invoice_number'], 'description' => 'Purchase from '.$d['supplier_name'], 'cashflow' => $d['settlement'] === 'payable' ? 'none' : 'operating', 'source_type' => 'purchase', 'source_id' => (string) $p->id, 'lines' => $lines]);
            Audit::record('accounting.purchase_posted', (string) $p->id);

            return $p;
        }, 3);
    }
}

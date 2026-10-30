<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ForeignAccount;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;

final class ForeignTradeSettlement
{
    public function settle(User $u, TradeDocument $target, array $input): TradeDocument
    {
        $t = app(TradeLedger::class);
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:100', 'foreign_account_id' => 'required|integer', 'original_amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/']])->validate();
        if (! in_array($target->kind, ['bill', 'invoice', 'opening_payable', 'opening_receivable'])) {
            TradeLedger::fail('Choose an open bill or invoice.');
        }InventoryAccess::warehouse($u, $target->warehouse_id);
        $payload = [...$d, 'target_id' => $target->id];
        if ($old = $t->existing($d['request_id'], $payload)) {
            return $old;
        }
        if ($target->book !== AccountingAccess::book() || $d['date'] < $target->date) {
            TradeLedger::fail('Settlement date or operating book mismatch.');
        }
        $a = ForeignAccount::findOrFail($d['foreign_account_id']);
        if (! in_array($a->kind, ['bank', 'safe', 'mobile']) || $a->branch_id !== Warehouse::findOrFail($target->warehouse_id)->branch_id) {
            TradeLedger::fail('Choose a foreign bank, safe or mobile account in the document branch.');
        }
        $snap = app(CurrencyRates::class)->snapshot(Currency::findOrFail($a->currency_id)->code, $d['date'], $d['original_amount']);
        $amount = BigDecimal::of($snap['base_amount']);
        if ($target->currency_snapshot) {
            if ($target->currency_snapshot['currency'] !== $snap['currency']) {
                TradeLedger::fail('Use the invoice currency for settlement.');
            }
            $out = app(TradeCurrency::class)->outstandingOriginal($target);
            $original = BigDecimal::of($d['original_amount']);
            if ($original->isGreaterThan($out)) {
                TradeLedger::fail('Settlement exceeds original-currency outstanding balance.');
            }
            $amount = $original->isEqualTo($out) ? BigDecimal::of($t->outstanding($target)) : BigDecimal::of($t->outstanding($target))->multipliedBy($original)->dividedBy($out, 2, RoundingMode::HalfUp);
        }
        try {
            $amount = $amount->toScale(2);
        } catch (RoundingNecessaryException) {
            TradeLedger::fail('Foreign settlement must convert to an exact base-currency cent amount.');
        }if (! $amount->isPositive() || $amount->isGreaterThan($t->outstanding($target))) {
            TradeLedger::fail('Settlement exceeds outstanding balance.');
        }$purchase = in_array($target->kind, ['bill', 'opening_payable']);
        $l = app(AccountingLedger::class);
        app(ForeignLedger::class)->post($u, $a->id, ['request_id' => $d['request_id'], 'date' => $d['date'], 'reference' => $d['reference'], 'direction' => $purchase ? 'decrease' : 'increase', 'amount' => $d['original_amount'], 'offset_account_id' => $l->account($purchase ? 'payable' : 'receivable')->id, 'cashflow' => 'operating'], true, $target->currency_snapshot ? (string) $amount : null);
        $doc = $t->document($u, $purchase ? 'supplier_payment' : 'customer_receipt', ['request_id' => $d['request_id'], 'date' => $d['date'], 'reference' => $d['reference'], 'warehouse_id' => $target->warehouse_id, 'supplier_id' => $target->supplier_id, 'customer_id' => $target->customer_id, 'parent_id' => $target->id, 'party_name' => $target->party_name], [['foreign_account_id' => $a->id, ...$snap]], (string) $amount, '0', (string) $amount, $payload);
        TradeAllocation::create(['document_id' => $doc->id, 'target_id' => $target->id, 'amount' => (string) $amount, 'original_amount' => $target->currency_snapshot ? $d['original_amount'] : null]);
        Audit::record('trade.foreign_settlement', (string) $doc->id);

        return $doc;
    }
}

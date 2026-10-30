<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TenantModule;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use Brick\Math\BigDecimal;

final class TradeCurrency
{
    public function convert(array $items, ?string $code, string $date): array
    {
        $base = Company::firstOrFail()->base_currency;
        if (! $code || $code === $base) {
            return ['items' => $items, 'snapshot' => null];
        }
        abort_unless(TenantModule::where('key', 'currencies')->where('enabled', true)->exists(), 403);
        $original = PosMath::sum(array_column($items, 'total'));
        $snapshot = app(CurrencyRates::class)->snapshot($code, $date, $original);
        foreach ($items as &$item) {
            $item['original'] = $item;
            foreach (['net', 'tax'] as $field) {
                $item[$field] = PosMath::money(BigDecimal::of($item[$field])->multipliedBy($snapshot['exchange_rate']));
            }$item['total'] = PosMath::money(BigDecimal::of($item['net'])->plus($item['tax']));
        }unset($item);
        $snapshot['base_amount'] = PosMath::sum(array_column($items, 'total'));

        return ['items' => $items, 'snapshot' => $snapshot];
    }

    public function outstandingOriginal(TradeDocument $doc): string
    {
        return (string) BigDecimal::of($doc->currency_snapshot['original_amount'])->minus(PosMath::sum(TradeAllocation::where('target_id', $doc->id)->pluck('original_amount'), 4))->toScale(4);
    }

    public function originalReturn(TradeDocument $invoice, array $items, array $prior): array
    {
        $original = collect($invoice->items)->keyBy('variant_id');
        $total = BigDecimal::zero();
        foreach ($items as &$item) {
            $o = $original[$item['variant_id']]['original'];
            $p = $prior[$item['variant_id']] ?? '0';
            $q = $item['quantity'];
            $net = PosMath::portion($o['net'], $o['quantity'], $p, $q);
            $tax = PosMath::portion($o['tax'], $o['quantity'], $p, $q);
            $item['original'] = [...$o, 'quantity' => $q, 'net' => $net, 'tax' => $tax, 'total' => (string) BigDecimal::of($net)->plus($tax)];
            $total = $total->plus($item['original']['total']);
        }unset($item);
        if ($total->isGreaterThan($this->outstandingOriginal($invoice))) {
            TradeLedger::fail('Return exceeds unpaid original-currency balance.');
        }

        return ['items' => $items, 'snapshot' => [...$invoice->currency_snapshot, 'original_amount' => (string) $total, 'base_amount' => PosMath::sum(array_column($items, 'total'))]];
    }
}

<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\PurchaseOrder;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;

final class BusinessReports
{
    public function run(User $user, array $filters): array
    {
        $empty = ['sales' => null, 'procurement' => null, 'aging' => null];
        if (! AccountingSetting::exists() || ! TenantModule::where('key', 'accounting')->where('enabled', true)->exists()) {
            return $empty;
        }
        $ids = AccountingAccess::branches($user)->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->pluck('id');
        $warehouses = Warehouse::whereIn('branch_id', $ids)->pluck('id');
        $sales = $user->canDo('sales.view') && TenantModule::where('key', 'sales')->where('enabled', true)->exists();
        $purchase = $user->canDo('purchasing.view') && TenantModule::where('key', 'purchasing')->where('enabled', true)->exists();
        $kinds = [...($sales ? ['invoice', 'invoice_reversal', 'credit_return', 'customer_receipt'] : []), ...($purchase ? ['bill', 'receipt', 'supplier_payment'] : [])];
        $documents = TradeDocument::where('book', $filters['book'])->whereIn('warehouse_id', $warehouses)->whereIn('kind', $kinds)
            ->whereBetween('date', [$filters['from'], $filters['to']])->orderBy('date')->orderBy('id')->get();
        $register = $documents->map(fn ($d) => [...$d->only('id', 'kind', 'date', 'due_on', 'party_name', 'customer_id', 'supplier_id', 'net', 'tax', 'total', 'reference'), 'number' => $d->kind === 'invoice_reversal' ? $d->reference : $d->display_number,
            'currency' => $d->currency_snapshot['currency'] ?? null, 'original_total' => $d->currency_snapshot['original_amount'] ?? null,
            'sign' => in_array($d->kind, ['invoice_reversal', 'credit_return'], true) ? -1 : 1])->all();
        $sum = fn ($kinds, $field) => PosMath::sum(array_map(fn ($r) => (string) BigDecimal::of($r[$field])->multipliedBy($r['sign']), array_filter($register, fn ($r) => in_array($r['kind'], $kinds, true))), 4);
        $partySummary = function (array $kinds, string $key) use ($register): array {
            $parties = [];
            foreach ($register as $r) {
                if (! in_array($r['kind'], $kinds, true)) {
                    continue;
                }
                $id = $r[$key];
                $parties[$id] ??= ['id' => $id, 'name' => $r['party_name'], 'net' => '0.0000', 'tax' => '0.0000', 'total' => '0.0000'];
                foreach (['net', 'tax', 'total'] as $column) {
                    $parties[$id][$column] = (string) BigDecimal::of($parties[$id][$column])->plus(BigDecimal::of($r[$column])->multipliedBy($r['sign']))->toScale(4);
                }
            }

            return array_values($parties);
        };
        $result = $empty;
        if ($sales) {
            $result['sales'] = ['by_party' => $partySummary(['invoice', 'invoice_reversal', 'credit_return'], 'customer_id'), 'net' => $sum(['invoice', 'invoice_reversal', 'credit_return'], 'net'), 'tax' => $sum(['invoice', 'invoice_reversal', 'credit_return'], 'tax'),
                'total' => $sum(['invoice', 'invoice_reversal', 'credit_return'], 'total'), 'receipts' => $sum(['customer_receipt'], 'total'),
                'documents' => array_values(array_filter($register, fn ($r) => in_array($r['kind'], ['invoice', 'invoice_reversal', 'credit_return', 'customer_receipt'], true)))];
        }
        if ($purchase) {
            $orders = PurchaseOrder::where('book', $filters['book'])->whereIn('warehouse_id', $warehouses)->whereBetween('date', [$filters['from'], $filters['to']])->orderBy('date')->get();
            $result['procurement'] = ['by_party' => $partySummary(['bill'], 'supplier_id'), 'billed' => $sum(['bill'], 'total'), 'net' => $sum(['bill'], 'net'), 'tax' => $sum(['bill'], 'tax'), 'paid' => $sum(['supplier_payment'], 'total'),
                'received' => $sum(['receipt'], 'total'), 'order_count' => $orders->count(),
                'orders' => $orders->map(fn ($o) => [...$o->only('id', 'number', 'date', 'expected_on', 'status', 'total'), 'total' => PosMath::sum(array_map(fn ($i) => (string) BigDecimal::of($i['quantity'])->multipliedBy($i['unit_cost']), $o->items), 4), 'supplier_name' => $o->supplier_name, 'currency' => $o->currency_snapshot['currency'] ?? null, 'original_total' => $o->currency_snapshot['original_amount'] ?? null])->all(),
                'documents' => array_values(array_filter($register, fn ($r) => in_array($r['kind'], ['bill', 'receipt', 'supplier_payment'], true)))];
        }
        if ($sales || $purchase) {
            $result['aging'] = app(TradeAging::class)->run($user, $filters);
        }

        return $result;
    }
}

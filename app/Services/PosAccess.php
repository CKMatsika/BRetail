<?php

namespace App\Services;

use App\Models\PosCurrencyOperation;
use App\Models\PosRefundPayment;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;

final class PosAccess
{
    public function lock(User $user, string $permission): void
    {
        app(Catalog::class)->lock();
        $user->refresh();
        $user->unsetRelation('role');
        InventoryAccess::permit($user, $permission);
        abort_unless(TenantModule::where('key', 'pos')->where('enabled', true)->exists(), 403, 'Point of sale is disabled.');
    }

    public function sales(User $user)
    {
        $q = PosSale::whereIn('warehouse_id', InventoryAccess::warehouses($user)->select('id'));
        if (! $user->canDo('pos.manage')) {
            $q->where('user_id', $user->id);
        }

        return $q;
    }

    public function sale(User $user, int $id): PosSale
    {
        InventoryAccess::permit($user, 'pos.view');

        return $this->sales($user)->findOrFail($id);
    }

    public function detail(PosSale $sale): array
    {
        $sale->load('items', 'payments', 'returns.items', 'returns.payments');
        $returnIds = $sale->returns->pluck('id');
        $items = [];
        foreach ($sale->items as $item) {
            $returned = PosMath::sum(PosReturnItem::whereIn('return_id', $returnIds)->where('sale_item_id', $item->id)->get()->pluck('quantity'), 3);
            $items[] = [...$item->toArray(), 'returned_quantity' => $returned, 'remaining_quantity' => (string) BigDecimal::of($item->quantity)->minus($returned)->toScale(3)];
        }
        $remaining = [];
        foreach ($sale->payments as $p) {
            $refunded = PosMath::sum(PosRefundPayment::whereIn('return_id', $returnIds)->where('method', $p->method)->get()->pluck('amount'));
            $remaining[$p->method] = PosMath::money(BigDecimal::of($p->amount)->minus($refunded));
        }

        return ['currency_tenders' => PosCurrencyOperation::where('sale_id', $sale->id)->get()->toArray(), ...$sale->toArray(), 'items' => $items, 'remaining_payments' => $remaining, 'refunded_total' => PosMath::sum($sale->returns->pluck('total'))];
    }
}

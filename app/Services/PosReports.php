<?php

namespace App\Services;

use App\Models\PosReturn;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

final class PosReports
{
    public function daily(User $user, ?string $date = null): array
    {
        InventoryAccess::permit($user, 'pos.view');
        $timezone = config('pos.report_timezone');
        $date ??= CarbonImmutable::now($timezone)->format('Y-m-d');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $end = $start->addDay();
        $from = $start->utc();
        $until = $end->utc();
        $sales = app(PosAccess::class)->sales($user)->where('created_at', '>=', $from)->where('created_at', '<', $until)->get();
        $returns = PosReturn::whereIn('warehouse_id', InventoryAccess::warehouses($user)->select('id'))->when(! $user->canDo('pos.manage'), fn ($q) => $q->where('user_id', $user->id))->where('created_at', '>=', $from)->where('created_at', '<', $until)->get();
        $gross = PosMath::sum($sales->pluck('total'));
        $refunds = PosMath::sum($returns->pluck('total'));

        return ['date' => $date, 'timezone' => $timezone, 'sale_count' => $sales->count(), 'return_count' => $returns->count(), 'gross_sales' => $gross, 'tax' => PosMath::sum($sales->pluck('tax')), 'refunds' => $refunds, 'net_recorded' => PosMath::money(BigDecimal::of($gross)->minus($refunds))];
    }
}

<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\Branch;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

final class ReportsAnalytics
{
    public function run(User $user, array $input): array
    {
        abort_unless($user->canDo('reports.view'), 403);
        $timezone = config('pos.report_timezone');
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        $data = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'book' => ['nullable', Rule::in(['training', 'live'])],
        ])->validate();
        $from = $data['from'] ?? CarbonImmutable::now($timezone)->startOfMonth()->format('Y-m-d');
        $to = $data['to'] ?? $today;
        abort_if($to < $from, 422, 'The end date must be on or after the start date.');
        abort_if(CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) > 366, 422, 'Choose a date range of one year or less.');

        $branches = AccountingAccess::branches($user);
        if (! empty($data['branch_id'])) {
            AccountingAccess::branch($user, (int) $data['branch_id']);
            $branches->whereKey((int) $data['branch_id']);
        }
        $branchRows = $branches->orderBy('name')->get(['id', 'name']);
        $branchIds = $branchRows->pluck('id');
        $warehouseIds = DB::table('warehouses')->whereIn('branch_id', $branchIds)->pluck('id');
        $book = $data['book'] ?? (AccountingSetting::first()?->live_enabled ? 'live' : 'training');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc();

        $salesByCurrency = [];
        $daily = [];
        $salesCount = 0;
        $salesEnabled = TenantModule::where('key', 'pos')->where('enabled', true)->exists();
        if ($salesEnabled) {
            $sales = PosSale::whereIn('warehouse_id', $warehouseIds)->where('mode', $book)
                ->when(! $user->canDo('pos.manage'), fn ($query) => $query->where('user_id', $user->id))
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderBy('created_at')->cursor();
            foreach ($sales as $sale) {
                $salesCount++;
                $currency = $sale->currency;
                $salesByCurrency[$currency] ??= ['currency' => $currency, 'sales' => BigDecimal::zero(), 'refunds' => BigDecimal::zero(), 'net' => '0.00', 'tax' => BigDecimal::zero(), 'count' => 0];
                $salesByCurrency[$currency]['sales'] = $salesByCurrency[$currency]['sales']->plus($sale->total);
                $salesByCurrency[$currency]['tax'] = $salesByCurrency[$currency]['tax']->plus($sale->tax);
                $salesByCurrency[$currency]['count']++;
                $day = CarbonImmutable::parse($sale->created_at)->setTimezone($timezone)->format('Y-m-d');
                $daily[$day] ??= ['date' => $day, 'transactions' => 0];
                $daily[$day]['transactions']++;
            }
            $returns = PosReturn::whereIn('warehouse_id', $warehouseIds)->where('mode', $book)
                ->when(! $user->canDo('pos.manage'), fn ($query) => $query->where('user_id', $user->id))
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderBy('created_at')->cursor();
            foreach ($returns as $return) {
                $currency = $return->currency;
                $salesByCurrency[$currency] ??= ['currency' => $currency, 'sales' => BigDecimal::zero(), 'refunds' => BigDecimal::zero(), 'net' => '0.00', 'tax' => BigDecimal::zero(), 'count' => 0];
                $salesByCurrency[$currency]['refunds'] = $salesByCurrency[$currency]['refunds']->plus($return->total);
            }
        }
        foreach ($salesByCurrency as &$row) {
            $row['sales'] = (string) $row['sales']->toScale(2);
            $row['refunds'] = (string) $row['refunds']->toScale(2);
            $row['net'] = (string) BigDecimal::of($row['sales'])->minus($row['refunds'])->toScale(2);
            $row['tax'] = (string) $row['tax']->toScale(2);
        }
        unset($row);

        $inventoryEnabled = TenantModule::where('key', 'inventory')->where('enabled', true)->exists();
        $inventory = ['variant_count' => 0, 'quantity' => '0.000', 'value' => '0.00', 'low_stock_count' => 0, 'low_stock' => []];
        if ($inventoryEnabled && $warehouseIds->isNotEmpty()) {
            $stock = DB::table('stock_balances')->join('product_variants', function ($join) {
                $join->on('product_variants.id', '=', 'stock_balances.variant_id')->on('product_variants.tenant_id', '=', 'stock_balances.tenant_id');
            })->whereIn('stock_balances.warehouse_id', $warehouseIds)->where('product_variants.active', true)
                ->select('product_variants.id', 'product_variants.name', 'product_variants.sku', 'product_variants.reorder_level')
                ->selectRaw('SUM(stock_balances.quantity) AS quantity, SUM(stock_balances.value) AS value')
                ->groupBy('product_variants.id', 'product_variants.name', 'product_variants.sku', 'product_variants.reorder_level')->get();
            $inventory['variant_count'] = $stock->where('quantity', '!=', 0)->count();
            $totalQuantity = BigDecimal::zero();
            $totalValue = BigDecimal::zero();
            foreach ($stock as $balance) {
                $totalQuantity = $totalQuantity->plus($balance->quantity);
                $totalValue = $totalValue->plus($balance->value);
            }
            $inventory['quantity'] = (string) $totalQuantity->toScale(3);
            $inventory['value'] = (string) $totalValue->toScale(2);
            $inventory['low_stock'] = $stock->filter(fn ($item) => (float) $item->reorder_level > 0 && (float) $item->quantity <= (float) $item->reorder_level)
                ->sortBy('quantity')->take(10)->map(fn ($item) => ['name' => $item->name, 'sku' => $item->sku, 'quantity' => (string) BigDecimal::of($item->quantity)->toScale(3), 'reorder_level' => (string) BigDecimal::of($item->reorder_level)->toScale(3)])->values()->all();
            $inventory['low_stock_count'] = $stock->filter(fn ($item) => (float) $item->reorder_level > 0 && (float) $item->quantity <= (float) $item->reorder_level)->count();
        }

        $topItems = [];
        if ($salesEnabled && $warehouseIds->isNotEmpty()) {
            $topItems = DB::table('pos_sale_items')->join('pos_sales', function ($join) {
                $join->on('pos_sales.id', '=', 'pos_sale_items.sale_id')->on('pos_sales.tenant_id', '=', 'pos_sale_items.tenant_id');
            })->whereIn('pos_sales.warehouse_id', $warehouseIds)->where('pos_sales.mode', $book)
                ->when(! $user->canDo('pos.manage'), fn ($query) => $query->where('pos_sales.user_id', $user->id))
                ->where('pos_sales.created_at', '>=', $start)->where('pos_sales.created_at', '<', $end)
                ->select('pos_sale_items.description', 'pos_sale_items.sku')->selectRaw('SUM(pos_sale_items.quantity) AS quantity')
                ->groupBy('pos_sale_items.description', 'pos_sale_items.sku')->orderByDesc('quantity')->limit(8)->get()
                ->map(fn ($item) => ['name' => $item->description, 'sku' => $item->sku, 'quantity' => (string) BigDecimal::of($item->quantity)->toScale(3)])->all();
        }

        $accounting = null;
        if (AccountingSetting::exists() && $user->canDo('accounting.view')) {
            $accounting = app(AccountingReports::class)->run($user, ['book' => $book, 'from' => $from, 'to' => $to, 'branch_id' => $data['branch_id'] ?? null]);
        }

        $days = [];
        for ($day = CarbonImmutable::parse($from, $timezone); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = $daily[$key] ?? ['date' => $key, 'transactions' => 0];
        }

        return [
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $data['branch_id'] ?? null, 'book' => $book],
            'branches' => $branchRows->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name])->all(),
            'currency_totals' => array_values($salesByCurrency),
            'sales_count' => $salesCount,
            'daily_sales' => $days,
            'top_items' => $topItems,
            'inventory' => $inventory,
            'accounting' => $accounting ? ['profit_loss' => $accounting['profit_loss'], 'cash_flow' => $accounting['cash_flow'], 'balance_sheet' => $accounting['balance_sheet']] : null,
            'base_currency' => AccountingSetting::first()?->currency,
            'timezone' => $timezone,
        ];
    }
}

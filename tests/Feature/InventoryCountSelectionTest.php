<?php

namespace Tests\Feature;

use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\Warehouse;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\StockCounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCountSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_count_posts_found_stock_using_catalogue_cost(): void
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'selected@inventory.test', 'password' => 'SecurePassword123', 'company_name' => 'Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::where('key', 'inventory')->update(['enabled' => true]);
        $catalog = app(Catalog::class);
        $p = $catalog->product($u, ['name' => 'Cycle count items', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => [], 'active' => true]);
        $data = ['name' => 'Standard', 'attributes' => [], 'cost_price' => '10', 'selling_price' => '15', 'wholesale_price' => '12', 'reorder_level' => '0', 'minimum_stock' => '0', 'active' => true];
        $a = $catalog->variant($u, $p, [...$data, 'sku' => 'COUNT-A']);
        $b = $catalog->variant($u, $p, [...$data, 'sku' => 'COUNT-B']);
        $service = app(StockCounting::class);
        $count = $service->start($u, ['warehouse_id' => Warehouse::first()->id, 'reference' => 'SELECTED', 'variant_ids' => [$a->id]]);
        $this->assertCount(1, $count->lines);
        $service->finish($u, $count, ['lines' => [['variant_id' => $a->id, 'counted_quantity' => '4.500']]]);
        $balance = StockBalance::first();
        $this->assertSame('4.500', $balance->quantity);
        $this->assertSame('45.0000', $balance->value);
        $this->assertSame('10.0000', $balance->average_cost);
        $this->assertDatabaseMissing('stock_balances', ['variant_id' => $b->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\PosSale;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\PosCart;
use App\Services\PosHolds;
use App\Services\PosReports;
use App\Services\PosReturns;
use App\Services\PosSales;
use App\Services\PosShifts;
use App\Services\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $quantity = '10', string $cost = '10', string $price = '20'): array
    {
        config(['pos.training_enabled' => true]);
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'edge@pos.test', 'password' => 'SecurePassword123', 'company_name' => 'Edge Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'pos'])->update(['enabled' => true]);
        $w = Warehouse::first();
        $p = app(Catalog::class)->product($u, ['name' => 'Edge item', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => [], 'active' => true]);
        $v = app(Catalog::class)->variant($u, $p, ['name' => 'Standard', 'sku' => 'EDGE', 'cost_price' => $cost, 'selling_price' => $price, 'wholesale_price' => '0', 'minimum_stock' => '0', 'reorder_level' => '0', 'attributes' => [], 'active' => true]);
        app(StockLedger::class)->post($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Test', 'lines' => [['variant_id' => $v->id, 'quantity' => $quantity, 'unit_cost' => $cost]]]);
        $s = app(PosShifts::class)->open($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'opening_cash' => '0']);
        $this->assertSame('open', $s->status);

        return [$u, $w, $p, $v, $s];
    }

    private function checkout(array $f, string $quantity): PosSale
    {
        [$u,$w,$p,$v,$s] = $f;
        $cart = ['warehouse_id' => $w->id, 'lines' => [['variant_id' => $v->id, 'quantity' => $quantity]]];
        $q = app(PosCart::class)->quote($u, $cart);

        return app(PosSales::class)->checkout($u, [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'quote_token' => $q['quote_token'], 'payments' => [['method' => 'cash', 'amount' => $q['total']]]]);
    }

    private function refund(array $f, PosSale $sale, string $quantity)
    {
        [$u,$w,$p,$v,$s] = $f;
        $lines = [['sale_item_id' => $sale->items->first()->id, 'quantity' => $quantity]];
        $q = app(PosReturns::class)->quote($u, $sale, ['lines' => $lines]);

        return app(PosReturns::class)->post($u, $sale, ['request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'quote_token' => $q['quote_token'], 'reason' => 'Fractional return', 'lines' => $lines, 'payments' => $q['total'] === '0.00' ? [] : [['method' => 'cash', 'amount' => $q['total']]]]);
    }

    public function test_fractional_returns_preserve_total_refund_and_original_stock_value(): void
    {
        $f = $this->fixture('0.003', '0.0333', '333.3333');
        $sale = $this->checkout($f, '0.003');
        $this->assertSame('1.00', $sale->total);
        $returns = [];
        foreach (range(1, 3) as $i) {
            $returns[] = $this->refund($f, $sale, '0.001');
        }$this->assertSame(['0.33', '0.34', '0.33'], array_map(fn ($r) => $r->total, $returns));
        $this->assertSame('0.003', StockBalance::first()->quantity);
        $this->assertSame('0.0001', StockBalance::first()->value);
        $this->assertSame('0.0000', $returns[0]->items->first()->cost_value);
        $this->assertSame('0.0001', $returns[1]->items->first()->cost_value);
        $this->assertTrue(app(StockLedger::class)->reconcile($f[0], $f[1]->id)['balanced']);
    }

    public function test_zero_money_fractional_return_restores_stock_without_fake_payment(): void
    {
        $f = $this->fixture('10', '10', '0.1');
        $sale = $this->checkout($f, '1');
        $return = $this->refund($f, $sale, '0.001');
        $this->assertSame('0.00', $return->total);
        $this->assertCount(0, $return->payments);
        $this->assertSame('9.001', StockBalance::first()->quantity);
        $this->assertSame('90.0100', StockBalance::first()->value);
    }

    public function test_daily_summary_uses_configured_day_and_excludes_other_dates(): void
    {
        $f = $this->fixture();
        $this->checkout($f, '2');
        $today = app(PosReports::class)->daily($f[0]);
        $this->assertSame('40.00', $today['gross_sales']);
        $this->assertSame(1, $today['sale_count']);
        $this->assertSame('Africa/Harare', $today['timezone']);
        $this->assertSame('0.00', app(PosReports::class)->daily($f[0], '2000-01-01')['gross_sales']);
    }

    public function test_inactive_item_can_be_returned_but_not_sold(): void
    {
        $f = $this->fixture();
        $sale = $this->checkout($f, '1');
        $f[3]->update(['active' => false]);
        $r = $this->refund($f, $sale, '1');
        $this->assertSame('20.00', $r->total);
        $this->assertSame('10.000', StockBalance::first()->quantity);
    }

    public function test_closed_shift_database_identity_is_immutable(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL shift guard.');
        }$f = $this->fixture();
        app(PosShifts::class)->close($f[0], $f[4]->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '0']);
        $this->expectException(QueryException::class);
        DB::table('pos_sessions')->where('id', $f[4]->id)->update(['status' => 'open']);
    }

    public function test_manager_can_reconcile_inactive_cashier_shift_and_cancel_held_cart(): void
    {
        $f = $this->fixture();
        $hold = app(PosHolds::class)->create($f[0], ['request_id' => (string) Str::uuid(), 'warehouse_id' => $f[1]->id, 'label' => 'Waiting customer', 'lines' => [['variant_id' => $f[3]->id, 'quantity' => '1']]]);
        $manager = new User(['name' => 'Manager', 'email' => 'manager@edge.test', 'password' => 'SecurePassword123']);
        $manager->tenant_id = $f[0]->tenant_id;
        $manager->role_id = Role::where('name', 'Branch Manager')->first()->id;
        $manager->save();
        $manager->branches()->attach($f[1]->branch_id, ['tenant_id' => $manager->tenant_id]);
        $f[0]->active = false;
        $f[0]->save();
        app(PosHolds::class)->cancel($manager, $hold->id);
        $closed = app(PosShifts::class)->close($manager, $f[4]->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '0', 'note' => 'Manager closure after employee deactivation']);
        $this->assertSame($manager->id, $closed->closed_by);
        $this->assertSame($f[0]->id, $closed->user_id);
        $this->assertSame('closed', $closed->status);
        $this->assertSame('cancelled', $hold->fresh()->status);
    }

    public function test_branch_cannot_be_deactivated_with_an_open_pos_shift(): void
    {
        $f = $this->fixture();
        $this->actingAs($f[0])->patchJson('/branches/'.$f[1]->branch_id, ['name' => 'Main', 'active' => false])->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->assertDatabaseHas('branches', ['id' => $f[1]->branch_id, 'active' => true]);
    }

    public function test_receipt_search_retains_tenant_scoping(): void
    {
        $f = $this->fixture();
        $sale = $this->checkout($f, '1');
        $this->actingAs($f[0])->getJson('/api/v1/pos?receipt='.$sale->receipt_number)->assertOk()->assertJsonCount(1, 'data.sales');
        $this->getJson('/api/v1/pos?receipt=DOES-NOT-EXIST')->assertOk()->assertJsonCount(0,'data.sales');
    }
}

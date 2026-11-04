<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\PosAccess;
use App\Services\PosAccountingGate;
use App\Services\PosCart;
use App\Services\PosHolds;
use App\Services\PosReturns;
use App\Services\PosSales;
use App\Services\PosShifts;
use App\Services\ReportsAnalytics;
use App\Services\StockLedger;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    private ProductVariant $variant;

    private PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['pos.training_enabled' => true]);
        $this->owner = $this->owner();
        $this->warehouse = Warehouse::first();
        $this->product = app(Catalog::class)->product($this->owner, ['name' => 'Cotton Romper', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $this->variant = app(Catalog::class)->variant($this->owner, $this->product, ['name' => 'Pink', 'sku' => 'ROMPER-PINK', 'barcode' => '600123456', 'cost_price' => '10', 'selling_price' => '20', 'wholesale_price' => '18', 'minimum_stock' => '0', 'reorder_level' => '1', 'active' => true, 'attributes' => []]);
        $this->stock('10', '10');
        $this->session = app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '50']);
    }

    private function owner(string $email = 'pos-owner@example.test'): User
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => $email, 'password' => 'SecurePassword123', 'company_name' => 'POS Shop', 'industry' => 'apparel', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main warehouse', 'tax_rate' => '0']);
        TenantModule::whereIn('key', ['inventory', 'pos'])->update(['enabled' => true]);
        DB::table('tenant_subscriptions')->where('tenant_id', $u->tenant_id)->update(['status' => 'active']);

        return $u;
    }

    private function member(string $role = 'Cashier', ?Warehouse $warehouse = null): User
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $u = new User(['name' => $role, 'email' => Str::uuid().'@example.test', 'password' => 'SecurePassword123']);
        $u->tenant_id = $this->owner->tenant_id;
        $u->role_id = Role::where('name', $role)->first()->id;
        $u->save();
        $u->branches()->attach(($warehouse ?? $this->warehouse)->branch_id, ['tenant_id' => $u->tenant_id]);

        return $u;
    }

    private function stock(string $qty, string $cost): void
    {
        app(StockLedger::class)->post($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment', 'reference' => 'TEST STOCK', 'reason' => 'Testing', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => $qty, 'unit_cost' => $cost]]]);
    }

    private function cart(string $qty = '1', string $discount = '0'): array
    {
        return ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $this->variant->id, 'quantity' => $qty, 'discount_percent' => $discount]]];
    }

    private function payload(string $qty = '1', array $payments = [], string $discount = '0'): array
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $cart = $this->cart($qty, $discount);
        $quote = app(PosCart::class)->quote($this->owner, $cart);

        return [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $this->session->id, 'quote_token' => $quote['quote_token'], 'payments' => $payments ?: [['method' => 'cash', 'amount' => $quote['total']]]];
    }

    private function sale(string $qty = '1', array $payments = [], string $discount = '0'): PosSale
    {
        return app(PosSales::class)->checkout($this->owner, $this->payload($qty, $payments, $discount));
    }

    private function refundPayload(PosSale $sale, string $qty = '1', array $payments = []): array
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $lines = [['sale_item_id' => $sale->items->first()->id, 'quantity' => $qty]];
        $q = app(PosReturns::class)->quote($this->owner, $sale, ['lines' => $lines]);

        return ['request_id' => (string) Str::uuid(), 'session_id' => $this->session->id, 'reason' => 'Customer return', 'lines' => $lines, 'quote_token' => $q['quote_token'], 'payments' => $payments ?: [['method' => 'cash', 'amount' => $q['total']]]];
    }

    public function test_cash_checkout_deducts_stock_records_change_and_reconciles(): void
    {
        $sale = $this->sale('2', [['method' => 'cash', 'amount' => '50']]);
        $this->assertSame('40.00', $sale->total);
        $this->assertSame('10.00', $sale->change);
        $this->assertSame('40.00', $sale->payments->first()->amount);
        $this->assertSame('50.00', $sale->payments->first()->tendered);
        $this->assertSame('8.000', StockBalance::first()->quantity);
        $this->assertSame('80.0000', StockBalance::first()->value);
        $this->assertSame('20.0000', $sale->items->first()->cost_value);
        $this->assertTrue(app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id)['balanced']);
        $this->assertSame('90.00', app(PosShifts::class)->summary($this->session)['expected_cash']);
    }

    public function test_split_payment_records_cash_change_only(): void
    {
        $sale = $this->sale('1', [['method' => 'card', 'amount' => '12', 'reference' => 'CARD-1'], ['method' => 'cash', 'amount' => '10']]);
        $this->assertSame('2.00', $sale->change);
        $this->assertSame('8.00', $sale->payments->firstWhere('method', 'cash')->amount);
        $this->assertSame('12.00', $sale->payments->firstWhere('method', 'card')->amount);
    }

    public function test_underpayment_noncash_overpayment_and_missing_reference_do_not_post(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $this->payload('1', [['method' => 'cash', 'amount' => '19']]))->assertUnprocessable();
        $this->postJson('/api/v1/pos/sales', $this->payload('1', [['method' => 'card', 'amount' => '21', 'reference' => 'X']]))->assertUnprocessable();
        $this->postJson('/api/v1/pos/sales', $this->payload('1', [['method' => 'card', 'amount' => '20']]))->assertUnprocessable();
        $this->assertDatabaseCount('pos_sales', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_tax_discount_and_rate_snapshots_are_server_calculated(): void
    {
        $company = Company::first();
        $company->update(['settings' => [...$company->settings, 'tax_rate' => '15']]);
        $sale = $this->sale('2', [], '10');
        $this->assertSame('40.00', $sale->subtotal);
        $this->assertSame('4.00', $sale->discount);
        $this->assertSame('5.40', $sale->tax);
        $this->assertSame('41.40', $sale->total);
        $this->assertSame('15.0000', $sale->items->first()->tax_rate);
        $company->update(['settings' => [...$company->settings, 'tax_rate' => '0']]);
        $this->assertSame('5.40', $sale->fresh()->tax);
    }

    public function test_changed_catalogue_price_requires_a_fresh_quote(): void
    {
        $d = $this->payload();
        $this->variant->update(['selling_price' => '25']);
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $d)->assertUnprocessable()->assertJsonValidationErrors('quote');
        $this->assertDatabaseCount('pos_sales', 0);
    }

    public function test_sale_retry_returns_original_even_after_shift_close(): void
    {
        $d = $this->payload();
        $a = app(PosSales::class)->checkout($this->owner, $d);
        app(PosShifts::class)->close($this->owner, $this->session->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '70']);
        $b = app(PosSales::class)->checkout($this->owner, $d);
        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('pos_sales', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_request_id_reuse_with_changed_data_is_rejected(): void
    {
        $d = $this->payload();
        app(PosSales::class)->checkout($this->owner, $d);
        $d['payments'][0]['amount'] = '25';
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $d)->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->assertDatabaseCount('pos_sales', 1);
    }

    public function test_last_unit_cannot_be_sold_twice_and_no_partial_payment_is_recorded(): void
    {
        $this->stock('-9', '0');
        $one = $this->payload();
        $two = $this->payload();
        app(PosSales::class)->checkout($this->owner, $one);
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $two)->assertUnprocessable();
        $this->assertDatabaseCount('pos_sales', 1);
        $this->assertDatabaseCount('pos_payments', 1);
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertSame('0.000', StockBalance::first()->quantity);
    }

    public function test_partial_returns_restore_original_cost_after_average_changes(): void
    {
        $sale = $this->sale('2');
        $this->stock('2', '30');
        $return = app(PosReturns::class)->post($this->owner, $sale, $this->refundPayload($sale));
        $this->assertSame('20.00', $return->total);
        $this->assertSame('10.0000', $return->items->first()->cost_value);
        $this->assertSame('11.000', StockBalance::first()->quantity);
        $this->assertSame('150.0000', StockBalance::first()->value);
        $this->assertTrue(app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id)['balanced']);
        $detail = app(PosAccess::class)->detail($sale);
        $this->assertSame('1.000', $detail['items'][0]['remaining_quantity']);
    }

    public function test_return_retry_does_not_duplicate_refund_or_stock(): void
    {
        $sale = $this->sale();
        $d = $this->refundPayload($sale);
        $a = app(PosReturns::class)->post($this->owner, $sale, $d);
        $b = app(PosReturns::class)->post($this->owner, $sale, $d);
        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('pos_returns', 1);
        $this->assertDatabaseCount('pos_refund_payments', 1);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_over_return_wrong_payment_method_and_over_refund_are_rejected(): void
    {
        $sale = $this->sale();
        $d = $this->refundPayload($sale, '1', [['method' => 'card', 'amount' => '20', 'reference' => 'REF']]);
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales/'.$sale->id.'/returns', $d)->assertUnprocessable();
        $d = $this->refundPayload($sale);
        $d['payments'][0]['amount'] = '21';
        $this->postJson('/api/v1/pos/sales/'.$sale->id.'/returns', $d)->assertUnprocessable();
        $this->postJson('/api/v1/pos/sales/'.$sale->id.'/return-quote', ['lines' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '2']]])->assertUnprocessable();
        $this->assertDatabaseCount('pos_returns', 0);
    }

    public function test_closed_shift_rejects_new_sales_and_shift_close_retries_are_safe(): void
    {
        $d = ['request_id' => (string) Str::uuid(), 'counted_cash' => '49', 'note' => 'One dollar short'];
        $a = app(PosShifts::class)->close($this->owner, $this->session->id, $d);
        $b = app(PosShifts::class)->close($this->owner, $this->session->id, $d);
        $this->assertSame($a->id, $b->id);
        $this->assertSame('-1.00', $a->variance);
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $this->payload())->assertUnprocessable();
    }

    public function test_shift_open_idempotency_and_one_open_shift_limit(): void
    {
        $d = ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '0'];
        $this->actingAs($this->owner)->postJson('/api/v1/pos/shifts', $d)->assertUnprocessable();
        $cashier = $this->member();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $a = app(PosShifts::class)->open($cashier, $d);
        $b = app(PosShifts::class)->open($cashier, $d);
        $this->assertSame($a->id, $b->id);
    }

    public function test_cashier_cannot_use_another_cashier_shift_override_large_discount_or_refund(): void
    {
        $cashier = $this->member();
        $d = $this->payload();
        $this->actingAs($cashier)->postJson('/api/v1/pos/sales', $d)->assertForbidden();
        $this->postJson('/api/v1/pos/quote', $this->cart('1', '11'))->assertUnprocessable();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $sale = $this->sale();
        $this->postJson('/api/v1/pos/sales/'.$sale->id.'/returns', ['request_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_cashier_receipt_details_hide_cost_and_other_cashier_sales(): void
    {
        $cashier = $this->member();
        $s = app(PosShifts::class)->open($cashier, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '0']);
        $d = $this->payload();
        $d['session_id'] = $s->id;
        $sale = app(PosSales::class)->checkout($cashier, $d);
        $this->actingAs($cashier)->getJson('/api/v1/pos/sales/'.$sale->id)->assertOk()->assertJsonMissingPath('data.items.0.cost_value');
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $other = $this->sale();
        $this->getJson('/api/v1/pos/sales/'.$other->id)->assertNotFound();
        $this->get('/pos/sales/'.$other->id.'/receipt')->assertNotFound();
    }

    public function test_tenant_and_branch_isolation_for_pos(): void
    {
        $sale = $this->sale();
        $foreign = $this->owner('foreign-pos@example.test');
        $this->actingAs($foreign)->getJson('/api/v1/pos/sales/'.$sale->id)->assertNotFound();
        $this->get('/pos/sales/'.$sale->id.'/receipt')->assertNotFound();
        $this->getJson('/api/v1/pos')->assertOk()->assertJsonCount(0, 'data.sales');
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $branch = Branch::create(['name' => 'Other branch', 'code' => 'OTHER']);
        $warehouse = Warehouse::create(['name' => 'Other warehouse', 'branch_id' => $branch->id]);
        $manager = $this->member('Branch Manager', $warehouse);
        $this->actingAs($manager)->getJson('/api/v1/pos')->assertOk()->assertJsonCount(1, 'data.warehouses')->assertJsonCount(0, 'data.sales');
        $this->getJson('/api/v1/pos/sales/'.$sale->id)->assertNotFound();
    }

    public function test_holding_cart_reserves_nothing_and_consumption_is_once_only(): void
    {
        $cart = $this->cart();
        $hold = app(PosHolds::class)->create($this->owner, [...$cart, 'request_id' => (string) Str::uuid(), 'label' => 'Waiting for customer']);
        $this->assertDatabaseCount('stock_movements', 1);
        $d = [...$this->payload(), 'hold_id' => $hold->id];
        $sale = app(PosSales::class)->checkout($this->owner, $d);
        $this->assertSame($hold->id, $sale->hold_id);
        $this->assertSame('consumed', $hold->fresh()->status);
        $d = $this->payload();
        $d['hold_id'] = $hold->id;
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', $d)->assertUnprocessable();
        $this->assertDatabaseCount('pos_sales', 1);
    }

    public function test_customer_snapshot_and_printable_training_receipt(): void
    {
        $customer = app(PosHolds::class)->customer($this->owner, ['name' => 'Jane Customer', 'phone' => '0770000000']);
        $d = [...$this->payload(), 'customer_id' => $customer->id];
        $sale = app(PosSales::class)->checkout($this->owner, $d);
        $customer->update(['name' => 'New name']);
        $this->actingAs($this->owner)->get('/pos/sales/'.$sale->id.'/receipt')->assertOk()->assertSee('TRAINING RECEIPT')->assertSee('NOT A TAX INVOICE')->assertSee('Jane Customer');
    }

    public function test_module_gates_and_open_shift_disable_guard(): void
    {
        $this->actingAs($this->owner)->patchJson('/modules/pos', ['enabled' => false, 'preferred' => false])->assertUnprocessable();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        TenantModule::where('key', 'pos')->update(['enabled' => false]);
        $this->get('/pos')->assertForbidden();
        $this->getJson('/api/v1/pos')->assertForbidden();
        $this->postJson('/api/v1/pos/sales', [])->assertForbidden();
    }

    public function test_training_off_and_production_environment_block_checkout(): void
    {
        config(['pos.training_enabled' => false]);
        $this->actingAs($this->owner)->postJson('/api/v1/pos/sales', [])->assertStatus(423);
        config(['pos.training_enabled' => true]);
        app()->instance('env', 'production');
        try {
            app(PosAccountingGate::class)->mode();
            $this->fail('Production checkout should be locked.');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_postgres_prevents_direct_sale_mutation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger check.');
        }$this->sale();
        $this->expectException(QueryException::class);
        DB::table('pos_sales')->update(['total' => '0']);
    }

    public function test_public_inventory_endpoint_cannot_inject_pos_types_or_cost_overrides(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'sale', 'reference' => 'NO', 'reason' => 'Bypass attempt', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => '-1']]])->assertUnprocessable();
        $this->postJson('/api/v1/inventory/movements', ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment', 'reference' => 'NO', 'reason' => 'Bypass attempt', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '10', 'value_override' => '999']]])->assertUnprocessable();
    }

    public function test_analytics_net_tax_deducts_refund_tax_in_the_reporting_period(): void
    {
        $company = Company::firstOrFail();
        $company->update(['settings' => [...$company->settings, 'tax_rate' => '15']]);
        $sale = $this->sale('2');
        app(PosReturns::class)->post($this->owner, $sale, $this->refundPayload($sale));
        $today = AccountingLedger::today();
        $report = app(ReportsAnalytics::class)->run($this->owner, ['from' => $today, 'to' => $today, 'book' => 'training']);
        $this->assertSame('46.00', $report['currency_totals'][0]['sales']);
        $this->assertSame('23.00', $report['currency_totals'][0]['refunds']);
        $this->assertSame('23.00', $report['currency_totals'][0]['net']);
        $this->assertSame('3.00', $report['currency_totals'][0]['tax']);
        $this->assertNull($report['business']['sales']);
    }

    public function test_company_banking_details_are_not_added_to_pos_receipts(): void
    {
        $company = Company::firstOrFail();
        $company->update(['settings' => [...$company->settings, 'banking_details' => 'PRIVATE-BANK-00123456789']]);
        $sale = $this->sale();
        $this->actingAs($this->owner)->get('/pos/sales/'.$sale->id.'/receipt')->assertOk()->assertDontSee('Banking details')->assertDontSee('PRIVATE-BANK-00123456789');
    }
}

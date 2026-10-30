<?php

namespace Tests\Feature;

use App\Models\PosCurrencyOperation;
use App\Models\TenantModule;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\CurrencyRates;
use App\Services\ForeignLedger;
use App\Services\Onboarding;
use App\Services\PosCart;
use App\Services\PosForeign;
use App\Services\PosReturns;
use App\Services\PosShifts;
use App\Services\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosForeignTest extends TestCase
{
    use RefreshDatabase;

    public static function tenderCases(): array
    {
        return ['foreign_only' => [false], 'mixed_base_foreign' => [true]];
    }

    #[DataProvider('tenderCases')]
    public function test_foreign_drawer_change_original_rate_refund_and_count_reconcile(bool $mixed): void
    {
        config(['pos.training_enabled' => true]);
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'posfx@example.test', 'password' => 'SecurePassword123', 'company_name' => 'FX POS', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'pos', 'currencies'])->update(['enabled' => true]);
        $w = Warehouse::first();
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($u, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $rates = app(CurrencyRates::class);
        $currency = $rates->currency($u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $rate = $rates->propose($u, ['currency_id' => $currency->id, 'effective_on' => $today, 'rate' => '0.05', 'source' => 'Test']);
        $rates->approve($u, $rate->id);
        $fx = app(ForeignLedger::class);
        $safe = $fx->account($u, ['branch_id' => $w->branch_id, 'currency_id' => $currency->id, 'name' => 'Rand safe', 'kind' => 'safe']);
        $l = app(AccountingLedger::class);
        $fx->post($u, $safe->id, ['request_id' => (string) Str::uuid(), 'date' => $today, 'reference' => 'CAPITAL', 'direction' => 'increase', 'amount' => '1000', 'offset_account_id' => $l->account('opening_equity')->id, 'cashflow' => 'financing']);
        $p = app(Catalog::class)->product($u, ['name' => 'Item', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $v = app(Catalog::class)->variant($u, $p, ['name' => 'Standard', 'sku' => 'FXPOS', 'cost_price' => '5', 'selling_price' => '10', 'wholesale_price' => '10', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        app(StockLedger::class)->post($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Test', 'lines' => [['variant_id' => $v->id, 'quantity' => '2', 'unit_cost' => '5']]]);
        $shift = app(PosShifts::class)->open($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'opening_cash' => '0']);
        $service = app(PosForeign::class);
        $drawerInput = ['request_id' => (string) Str::uuid(), 'session_id' => $shift->id, 'safe_account_id' => $safe->id, 'amount' => '100'];
        $drawer = $service->openDrawer($u, $drawerInput);
        $this->assertSame($drawer->id, $service->openDrawer($u, $drawerInput)->id);
        $cart = ['warehouse_id' => $w->id, 'lines' => [['variant_id' => $v->id, 'quantity' => '1']]];
        $quote = app(PosCart::class)->quote($u, $cart);
        $input = [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $shift->id, 'quote_token' => $quote['quote_token'], 'payments' => $mixed ? [['method' => 'cash', 'amount' => '2']] : [], 'foreign_tenders' => [['drawer_id' => $drawer->id, 'amount' => $mixed ? '260' : '300']], 'change_drawer_id' => $drawer->id];
        $sale = $service->checkout($u, $input);
        $this->assertSame($sale->id, $service->checkout($u, $input)->id);
        $op = PosCurrencyOperation::where('sale_id', $sale->id)->first();
        $this->assertSame('100.00', $op->tenders[0]['original_change']);
        $this->assertSame($mixed ? '2.00' : '0.00', app(PosShifts::class)->summary($shift)['expected_cash']);
        $this->assertSame($mixed ? '260.0000' : '300.0000', $service->summary($shift)[0]['balance']['original']);
        $this->travel(1)->days();
        try {
            $r = $rates->propose($u, ['currency_id' => $currency->id, 'effective_on' => AccountingLedger::today(), 'rate' => '0.06', 'source' => 'Later']);
            $rates->approve($u, $r->id);
            $returnQuote = app(PosReturns::class)->quote($u, $sale, ['lines' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => '1']]]);
            $refundInput = ['request_id' => (string) Str::uuid(), 'session_id' => $shift->id, 'reason' => 'Return', 'quote_token' => $returnQuote['quote_token'], 'lines' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => '1']], 'payments' => $mixed ? [['method' => 'cash', 'amount' => '2']] : [], 'foreign_refunds' => [['source_drawer_id' => $drawer->id, 'drawer_id' => $drawer->id, 'amount' => $mixed ? '160' : '200']]];
            $return = $service->refund($u, $sale, $refundInput);
            $this->assertSame($return->id, $service->refund($u, $sale, $refundInput)->id);
            $this->assertSame('100.0000', $service->summary($shift)[0]['balance']['original']);
            $this->assertSame('0.00', app(PosShifts::class)->summary($shift)['expected_cash']);
            $service->closeDrawer($u, $drawer->id, ['counted_amount' => '100']);
            app(PosShifts::class)->close($u, $shift->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '0']);
            $this->assertSame(['original' => '1000.0000', 'base' => '50.0000'], $fx->balances($safe, 'training'));
            $this->assertSame('0.0000', $l->balance($l->account('cash')->id, 'training'));
            $this->assertTrue(app(StockLedger::class)->reconcile($u, $w->id)['balanced']);
            if (DB::connection()->getDriverName() === 'pgsql') {
                try {
                    DB::table('pos_currency_operations')->where('id', $op->id)->update(['foreign_base' => '999']);
                    $this->fail('Posted POS currency operations must be immutable.');
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            $this->travelBack();
        }
    }
}

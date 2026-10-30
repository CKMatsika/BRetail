<?php

namespace Tests\Feature;

use App\Models\TenantModule;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\CurrencyRates;
use App\Services\ForeignLedger;
use App\Services\Onboarding;
use App\Services\PurchaseOrders;
use App\Services\StockLedger;
use App\Services\Suppliers;
use App\Services\TradeLedger;
use App\Services\TradePurchasing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForeignPurchaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_currency_carries_receipt_cost_and_remeasures_bill_at_invoice_rate(): void
    {
        $user = app(Onboarding::class)->register([
            'name' => 'Owner', 'email' => 'foreign-purchase@example.test', 'password' => 'SecurePassword123',
            'company_name' => 'Foreign Purchase', 'industry' => 'general', 'base_currency' => 'USD',
            'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main',
        ]);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'purchasing', 'currencies'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($user, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $warehouse = \App\Models\Warehouse::firstOrFail();
        $supplier = app(Suppliers::class)->save($user, ['code' => 'FOREIGN', 'name' => 'Foreign Supplier', 'terms_days' => 30, 'active' => true]);
        $rates = app(CurrencyRates::class);
        $currency = $rates->currency($user, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $rate = $rates->propose($user, ['currency_id' => $currency->id, 'effective_on' => $today, 'rate' => '0.05', 'source' => 'Order rate']);
        $rates->approve($user, $rate->id);
        $product = app(Catalog::class)->product($user, ['name' => 'Part', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $variant = app(Catalog::class)->variant($user, $product, ['name' => 'Standard', 'sku' => 'FOREIGN-PART', 'cost_price' => '1', 'selling_price' => '2', 'wholesale_price' => '2', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        $orders = app(PurchaseOrders::class);
        $order = $orders->create($user, ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'date' => $today, 'currency' => 'ZAR', 'items' => [['variant_id' => $variant->id, 'quantity' => '2', 'unit_cost' => '100']]]);
        $this->assertSame('10.0000', $order->currency_snapshot['base_amount']);
        $this->assertSame('5.0000', $order->items[0]['unit_cost']);
        $orders->transition($user, $order->id, 'approve');

        $this->travel(1)->days();
        try {
            $billDate = AccountingLedger::today();
            $newRate = $rates->propose($user, ['currency_id' => $currency->id, 'effective_on' => $billDate, 'rate' => '0.06', 'source' => 'Bill rate']);
            $rates->approve($user, $newRate->id);
            $receipt = app(TradePurchasing::class)->receive($user, $order->id, ['request_id' => (string) Str::uuid(), 'date' => $billDate, 'reference' => 'DELIVERY', 'items' => [['variant_id' => $variant->id, 'quantity' => '2']]]);
            $this->assertSame('10.0000', $receipt->net);
            $this->assertSame('200.00', $receipt->currency_snapshot['original_amount']);
            $bill = app(TradePurchasing::class)->bill($user, $receipt->id, ['request_id' => (string) Str::uuid(), 'date' => $billDate, 'due_on' => $billDate, 'reference' => 'SUPPLIER-INV', 'tax_rate' => '10', 'items' => [['variant_id' => $variant->id, 'quantity' => '2']]]);
            $this->assertSame('220.00', $bill->currency_snapshot['original_amount']);
            $this->assertSame('0.060000000000', $bill->currency_snapshot['exchange_rate']);
            $this->assertSame('12.0000', $bill->net);
            $this->assertSame('1.2000', $bill->tax);
            $this->assertSame('13.2000', $bill->total);
            $ledger = app(AccountingLedger::class);
            $this->assertSame('2.0000', $ledger->balance($ledger->account('fx_loss')->id, 'training'));

            $foreign = app(ForeignLedger::class);
            $safe = $foreign->account($user, ['branch_id' => $warehouse->branch_id, 'currency_id' => $currency->id, 'name' => 'Rand safe', 'kind' => 'safe']);
            $foreign->post($user, $safe->id, ['request_id' => (string) Str::uuid(), 'date' => $billDate, 'reference' => 'CAPITAL', 'direction' => 'increase', 'amount' => '500', 'offset_account_id' => $ledger->account('opening_equity')->id, 'cashflow' => 'financing']);
            app(TradeLedger::class)->settle($user, $bill->id, ['request_id' => (string) Str::uuid(), 'date' => $billDate, 'reference' => 'SUPPLIER-PAY', 'foreign_account_id' => $safe->id, 'original_amount' => '220']);
            $this->assertSame('0.0000', app(TradeLedger::class)->outstanding($bill));
            $this->assertSame('280.0000', $foreign->balances($safe, 'training')['original']);
        } finally {
            $this->travelBack();
        }
    }
}

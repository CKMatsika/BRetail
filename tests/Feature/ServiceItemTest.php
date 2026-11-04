<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\CatalogCsv;
use App\Services\Onboarding;
use App\Services\PosCart;
use App\Services\PosReturns;
use App\Services\PosSales;
use App\Services\PosShifts;
use App\Services\StockCounting;
use App\Services\StockLedger;
use App\Services\TradeLedger;
use App\Services\TradeSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceItemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $service;

    private ProductVariant $serviceSku;

    private ProductVariant $stockSku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'services@example.test', 'password' => 'SecurePassword123', 'company_name' => 'ERP Services', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'sales', 'purchasing', 'pos'])->update(['enabled' => true]);
        $this->warehouse = Warehouse::firstOrFail();
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($this->owner, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $this->service = app(Catalog::class)->product($this->owner, $this->productData('ERP licence', 'service'));
        $this->serviceSku = $this->sku($this->service, 'ERP', '100');
        $physical = app(Catalog::class)->product($this->owner, $this->productData('Keyboard', 'stock'));
        $this->stockSku = $this->sku($physical, 'KEYBOARD', '10');
    }

    private function productData(string $name, string $type): array
    {
        return ['name' => $name, 'item_type' => $type, 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'attributes' => [], 'active' => true];
    }

    private function sku(Product $product, string $sku, string $price): ProductVariant
    {
        return app(Catalog::class)->variant($this->owner, $product, ['name' => 'Standard', 'sku' => $sku, 'cost_price' => '5', 'selling_price' => $price, 'wholesale_price' => $price, 'minimum_stock' => '0', 'reorder_level' => '0', 'attributes' => [], 'active' => true]);
    }

    private function request(array $extra = []): array
    {
        return ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'date' => AccountingLedger::today(), 'reference' => 'REF-'.Str::random(6), ...$extra];
    }

    private function quote(array $variants)
    {
        $customer = PosCustomer::firstOrCreate(['name' => 'ERP Customer']);
        app(TradeLedger::class)->credit($this->owner, ['customer_id' => $customer->id, 'credit_limit' => '10000', 'terms_days' => 30, 'active' => true]);

        return app(TradeSales::class)->quote($this->owner, $this->request(['customer_id' => $customer->id, 'due_on' => AccountingLedger::today(), 'items' => array_map(fn ($v) => ['variant_id' => $v->id, 'quantity' => '1', 'unit_price' => $v->selling_price, 'discount_percent' => '0'], $variants)]));
    }

    private function balance(string $role): string
    {
        $ledger = app(AccountingLedger::class);

        return $ledger->balance($ledger->account($role)->id, 'training');
    }

    public function test_service_invoices_tax_and_credit_returns_without_stock_or_inventory_cost(): void
    {
        $this->service->update(['tax_class' => 'standard']);
        $company = Company::firstOrFail();
        $company->update(['settings' => [...($company->settings ?? []), 'tax_rate' => '15']]);
        $quote = $this->quote([$this->serviceSku]);
        $request = $this->request(['accepted' => true]);
        $invoice = app(TradeSales::class)->invoice($this->owner, $quote->id, $request);
        $this->assertSame('115.0000', $invoice->total);
        $this->assertSame($invoice->id, app(TradeSales::class)->invoice($this->owner, $quote->id, $request)->id);
        $this->assertSame('0', $invoice->items[0]['cost_value']);
        $this->assertSame('115.0000', $this->balance('receivable'));
        $this->assertSame('0.0000', $this->balance('inventory'));
        $this->assertSame('0.0000', $this->balance('cogs'));
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
        app(TradeSales::class)->creditReturn($this->owner, $invoice->id, $this->request(['notes' => 'Licence cancelled', 'items' => [['variant_id' => $this->serviceSku->id, 'quantity' => '1']]]));
        $this->assertSame('0.0000', $this->balance('receivable'));
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_mixed_invoice_only_moves_and_costs_the_physical_item(): void
    {
        app(StockLedger::class)->post($this->owner, $this->request(['type' => 'opening', 'reason' => 'Opening stock', 'lines' => [['variant_id' => $this->stockSku->id, 'quantity' => '2', 'unit_cost' => '5']]]));
        $quote = $this->quote([$this->serviceSku, $this->stockSku]);
        $invoice = app(TradeSales::class)->invoice($this->owner, $quote->id, $this->request(['accepted' => true]));
        $this->assertSame('110.0000', $invoice->total);
        $this->assertSame('1.000', StockBalance::where('variant_id', $this->stockSku->id)->firstOrFail()->quantity);
        $this->assertSame('5.0000', $this->balance('cogs'));
        $this->assertFalse(StockMovement::where('variant_id', $this->serviceSku->id)->exists());
        app(TradeSales::class)->creditReturn($this->owner, $invoice->id, $this->request(['notes' => 'Both cancelled', 'items' => [['variant_id' => $this->serviceSku->id, 'quantity' => '1'], ['variant_id' => $this->stockSku->id, 'quantity' => '1']]]));
        $this->assertSame('2.000', StockBalance::where('variant_id', $this->stockSku->id)->firstOrFail()->quantity);
        $this->assertSame('0.0000', $this->balance('cogs'));
        $this->assertFalse(StockMovement::where('variant_id', $this->serviceSku->id)->exists());
    }

    public function test_services_can_be_sold_and_refunded_at_pos_without_stock(): void
    {
        $session = app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '0']);
        $cart = ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $this->serviceSku->id, 'quantity' => '1', 'discount_percent' => '0']]];
        $quote = app(PosCart::class)->quote($this->owner, $cart);
        $sale = app(PosSales::class)->checkout($this->owner, [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $session->id, 'quote_token' => $quote['quote_token'], 'payments' => [['method' => 'cash', 'amount' => '100']]]);
        $this->assertSame('0.0000', $sale->items->first()->cost_value);
        $lines = [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']];
        $refund = app(PosReturns::class)->quote($this->owner, $sale, ['lines' => $lines]);
        app(PosReturns::class)->post($this->owner, $sale, ['request_id' => (string) Str::uuid(), 'session_id' => $session->id, 'reason' => 'Cancelled licence', 'lines' => $lines, 'quote_token' => $refund['quote_token'], 'payments' => [['method' => 'cash', 'amount' => '100']]]);
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_services_are_excluded_from_counts_and_stock_adjustments_are_rejected(): void
    {
        $count = app(StockCounting::class)->start($this->owner, ['warehouse_id' => $this->warehouse->id, 'reference' => 'Count']);
        $this->assertSame([$this->stockSku->id], $count->lines->pluck('variant_id')->all());
        $this->expectException(ValidationException::class);
        app(StockLedger::class)->post($this->owner, $this->request(['type' => 'opening', 'reason' => 'Invalid service stock', 'lines' => [['variant_id' => $this->serviceSku->id, 'quantity' => '1', 'unit_cost' => '5']]]));
    }

    public function test_unposted_item_can_be_reclassified_but_posted_services_keep_their_type(): void
    {
        $changed = app(Catalog::class)->product($this->owner, $this->productData('Keyboard', 'service'), $this->stockSku->product);
        $this->assertSame('service', $changed->item_type);
        $quote = $this->quote([$this->serviceSku]);
        app(TradeSales::class)->invoice($this->owner, $quote->id, $this->request(['accepted' => true]));
        $this->expectException(ValidationException::class);
        app(Catalog::class)->product($this->owner, $this->productData('ERP licence', 'stock'), $this->service);
    }

    public function test_physical_items_still_require_stock_at_pos_and_invoicing(): void
    {
        $quote = $this->quote([$this->stockSku]);
        try {
            app(TradeSales::class)->invoice($this->owner, $quote->id, $this->request(['accepted' => true]));
            $this->fail('An unstocked physical item must not be invoiced.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        app(PosCart::class)->quote($this->owner, ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $this->stockSku->id, 'quantity' => '1']]]);
    }

    public function test_csv_import_preserves_service_type(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, [...CatalogCsv::HEADERS, 'item_type'], ',', '"', '');
        fputcsv($stream, ['Implementation', 'Standard', 'IMPL', '', '', '', '', '', 'hour', '0', '50', '50', '0', '{}', 'service'], ',', '"', '');
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('services.csv', stream_get_contents($stream));
        fclose($stream);
        app(CatalogCsv::class)->import($this->owner, $file);
        $this->assertSame('service', Product::where('name', 'Implementation')->firstOrFail()->item_type);
    }
}

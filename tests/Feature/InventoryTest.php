<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog;
use App\Services\CatalogCsv;
use App\Services\Onboarding;
use App\Services\StockCounting;
use App\Services\StockLedger;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->makeOwner();
        $this->warehouse = Warehouse::first();
        $this->product = app(Catalog::class)->product($this->owner, $this->productData());
        $this->variant = app(Catalog::class)->variant($this->owner, $this->product, $this->variantData());
    }

    private function makeOwner(string $email = 'owner@inventory.test'): User
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => $email, 'password' => 'SecurePassword123', 'company_name' => 'Inventory Shop', 'industry' => 'apparel', 'base_currency' => 'USD', 'branch_name' => 'Harare', 'branch_code' => 'HRE', 'warehouse_name' => 'Harare warehouse']);
        TenantModule::where('key', 'inventory')->update(['enabled' => true]);

        return $u;
    }

    private function productData(array $extra = []): array
    {
        return [...['name' => 'Cotton Romper', 'description' => 'Soft cotton', 'brand' => 'Little Steps', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => ['Age group' => 'Baby'], 'active' => true], ...$extra];
    }

    private function variantData(array $extra = []): array
    {
        return [...['name' => 'Pink / 0–3 months', 'sku' => 'ROMPER-PINK-03', 'barcode' => '600000001', 'cost_price' => '10.0000', 'selling_price' => '18.5000', 'wholesale_price' => '16.0000', 'reorder_level' => '5.000', 'minimum_stock' => '2.000', 'maximum_stock' => '100.000', 'attributes' => ['Size' => '0–3 months', 'Colour' => 'Pink'], 'active' => true], ...$extra];
    }

    private function document(string $quantity = '10', string $cost = '10', array $extra = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment', 'reference' => 'TEST', 'reason' => 'Test adjustment', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_cost' => $cost]]], ...$extra];
    }

    private function postStock(string $quantity = '10', string $cost = '10', array $extra = []): StockDocument
    {
        return app(StockLedger::class)->post($this->owner, $this->document($quantity, $cost, $extra));
    }

    public function test_opening_adjustment_and_weighted_average_reconcile_exactly(): void
    {
        $this->postStock('10', '10', ['type' => 'opening']);
        $this->postStock('5', '16');
        $this->postStock('-3', '0');
        $b = StockBalance::first();
        $this->assertSame('12.000', $b->quantity);
        $this->assertSame('144.0000', $b->value);
        $this->assertSame('12.0000', $b->average_cost);
        $this->assertTrue(app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id)['balanced']);
    }

    public function test_fractional_quantities_and_final_withdrawal_leave_no_rounding_residue(): void
    {
        $this->postStock('0.003', '0.3333');
        $this->postStock('-0.001', '0');
        $this->postStock('-0.002', '0');
        $b = StockBalance::first();
        $this->assertSame('0.000', $b->quantity);
        $this->assertSame('0.0000', $b->value);
        $this->assertSame('0.0000', $b->average_cost);
        $this->assertTrue(app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id)['balanced']);
    }

    public function test_retries_do_not_duplicate_stock_and_different_payload_is_rejected(): void
    {
        $d = $this->document();
        $ledger = app(StockLedger::class);
        $one = $ledger->post($this->owner, $d);
        $d['lines'][0]['quantity'] = '10.000';
        $two = $ledger->post($this->owner, $d);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $d['lines'][0]['quantity'] = '11';
        $this->expectException(ValidationException::class);
        $ledger->post($this->owner, $d);
    }

    public function test_negative_stock_is_rejected_without_partial_writes(): void
    {
        $this->postStock('1', '10');
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', $this->document('-2'))->assertUnprocessable();
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertSame('1.000', StockBalance::first()->quantity);
    }

    public function test_multi_line_document_rolls_back_all_lines_on_failure(): void
    {
        $other = app(Catalog::class)->variant($this->owner, $this->product, $this->variantData(['sku' => 'OTHER', 'barcode' => null]));
        $d = $this->document();
        $d['lines'][] = ['variant_id' => $other->id, 'quantity' => '-1', 'unit_cost' => '0'];
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', $d)->assertUnprocessable();
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_opening_stock_is_allowed_only_before_any_movement(): void
    {
        $this->postStock('1', '10', ['type' => 'opening']);
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', $this->document('2', '10', ['type' => 'opening']))->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_catalogue_hierarchy_and_variant_uniqueness(): void
    {
        $c = app(Catalog::class);
        $department = $c->classification($this->owner, ['name' => 'Clothing', 'level' => 'department']);
        $category = $c->classification($this->owner, ['name' => 'Baby', 'level' => 'category', 'parent_id' => $department->id]);
        $subcategory = $c->classification($this->owner, ['name' => 'Rompers', 'level' => 'subcategory', 'parent_id' => $category->id]);
        $p = $c->product($this->owner, $this->productData(['department_id' => $department->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id]));
        $this->assertSame($subcategory->id, $p->subcategory_id);
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/products/'.$p->id.'/variants', $this->variantData())->assertUnprocessable()->assertJsonValidationErrors(['sku', 'barcode']);
        $this->postJson('/api/v1/inventory/classifications', ['name' => 'Wrong', 'level' => 'subcategory', 'parent_id' => $department->id])->assertUnprocessable();
        $this->postJson('/api/v1/inventory/products', $this->productData(['category_id' => $category->id]))->assertUnprocessable();
    }

    public function test_posted_count_adjusts_stock_and_cannot_be_posted_twice(): void
    {
        $this->postStock('10', '12');
        $service = app(StockCounting::class);
        $count = $service->start($this->owner, ['warehouse_id' => $this->warehouse->id, 'reference' => 'COUNT-1']);
        $service->finish($this->owner, $count, ['lines' => [['variant_id' => $this->variant->id, 'counted_quantity' => '8']]]);
        $this->assertSame('8.000', StockBalance::first()->quantity);
        $this->assertSame('96.0000', StockBalance::first()->value);
        $this->assertSame('posted', $count->fresh()->status);
        $this->assertTrue(app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id)['balanced']);
        $this->expectException(ValidationException::class);
        $service->finish($this->owner, $count, ['lines' => [['variant_id' => $this->variant->id, 'counted_quantity' => '8']]]);
    }

    public function test_stale_count_is_rejected_and_can_be_cancelled(): void
    {
        $this->postStock();
        $service = app(StockCounting::class);
        $count = $service->start($this->owner, ['warehouse_id' => $this->warehouse->id, 'reference' => 'STALE']);
        $this->postStock('1', '10');
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/counts/'.$count->id.'/post', ['lines' => [['variant_id' => $this->variant->id, 'counted_quantity' => '9']]])->assertUnprocessable();
        $this->postJson('/api/v1/inventory/counts/'.$count->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('stock_counts', ['id' => $count->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_count_requires_every_line_and_only_one_draft_per_warehouse(): void
    {
        app(Catalog::class)->variant($this->owner, $this->product, $this->variantData(['sku' => 'SECOND', 'barcode' => null]));
        $count = app(StockCounting::class)->start($this->owner, ['warehouse_id' => $this->warehouse->id, 'reference' => 'FULL']);
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/counts/'.$count->id.'/post', ['lines' => [['variant_id' => $this->variant->id, 'counted_quantity' => '1']]])->assertUnprocessable();
        $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'reference' => 'DUPLICATE'])->assertUnprocessable();
    }

    public function test_disabled_inventory_blocks_web_and_api_and_open_count_blocks_disable(): void
    {
        app(StockCounting::class)->start($this->owner, ['warehouse_id' => $this->warehouse->id, 'reference' => 'OPEN']);
        $this->actingAs($this->owner)->patchJson('/modules/inventory', ['enabled' => false, 'preferred' => false])->assertUnprocessable();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        TenantModule::where('key', 'inventory')->update(['enabled' => false]);
        $this->get('/inventory')->assertForbidden();
        $this->getJson('/api/v1/inventory')->assertForbidden();
        $this->postJson('/api/v1/inventory/movements', $this->document())->assertForbidden();
    }

    public function test_foreign_tenant_cannot_read_or_mutate_catalogue_or_stock(): void
    {
        $foreign = $this->makeOwner('foreign@inventory.test');
        $this->actingAs($foreign)->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0, 'data.products');
        $this->patchJson('/api/v1/inventory/products/'.$this->product->id, $this->productData())->assertNotFound();
        $this->postJson('/api/v1/inventory/movements', $this->document())->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_branch_manager_cannot_adjust_stock_or_access_other_warehouse(): void
    {
        $branch = Branch::create(['name' => 'Bulawayo', 'code' => 'BYO']);
        $w = Warehouse::create(['name' => 'Other', 'branch_id' => $branch->id]);
        $user = new User(['name' => 'Manager', 'email' => 'manager@inventory.test', 'password' => 'SecurePassword123']);
        $user->tenant_id = $this->owner->tenant_id;
        $user->role_id = Role::where('name', 'Branch Manager')->first()->id;
        $user->save();
        $user->branches()->attach($this->warehouse->branch_id, ['tenant_id' => $user->tenant_id]);
        $this->actingAs($user)->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(1, 'data.warehouses');
        $this->postJson('/api/v1/inventory/movements', $this->document())->assertForbidden();
        $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $w->id, 'reference' => 'NO'])->assertNotFound();
    }

    public function test_cashier_cannot_read_inventory_and_auditor_cannot_write(): void
    {
        foreach (['Cashier', 'Auditor'] as $role) {
            app(TenantContext::class)->id = $this->owner->tenant_id;
            $user = new User(['name' => $role, 'email' => strtolower($role).'@inventory.test', 'password' => 'SecurePassword123']);
            $user->tenant_id = $this->owner->tenant_id;
            $user->role_id = Role::where('name', $role)->first()->id;
            $user->save();
            $this->actingAs($user)->getJson('/api/v1/inventory')->assertStatus($role === 'Cashier' ? 403 : 200);
            $this->postJson('/api/v1/inventory/products', $this->productData())->assertForbidden();
        }
    }

    private function csv(array $rows): UploadedFile
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, CatalogCsv::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, $row, ',', '"', '');
        }rewind($h);
        $contents = stream_get_contents($h);
        fclose($h);

        return UploadedFile::fake()->createWithContent('catalog.csv', $contents);
    }

    private function csvRow(string $sku = 'CSV-1'): array
    {
        return ['Imported romper', 'Blue / 3–6m', $sku, '', 'Clothing', 'Baby', 'Rompers', 'Little Steps', 'each', '8.25', '15.50', '14', '3', '{"Size":"3–6 months","Colour":"Blue"}'];
    }

    public function test_csv_import_creates_classifications_products_and_variants_atomically(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/import', ['file' => $this->csv([$this->csvRow()])])->assertOk()->assertJsonPath('data.imported', 1);
        $this->assertDatabaseHas('product_variants', ['sku' => 'CSV-1']);
        $this->assertDatabaseCount('classifications', 3);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_csv_failure_rolls_back_even_successful_earlier_rows(): void
    {
        $bad = $this->csvRow('CSV-2');
        $bad[9] = 'not money';
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/import', ['file' => $this->csv([$this->csvRow(), $bad])])->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('classifications', 0);
    }

    public function test_export_works_after_tenant_middleware_clears_context_and_escapes_formulas(): void
    {
        $this->product->update(['name' => '=DANGEROUS()']);
        $response = $this->actingAs($this->owner)->get('/inventory/export')->assertOk();
        $contents = $response->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $contents);
        $this->assertStringContainsString('ROMPER-PINK-03', $contents);
        $this->get('/inventory/template')->assertOk();
    }

    public function test_reconciliation_detects_projection_corruption(): void
    {
        $this->postStock();
        StockBalance::query()->update(['quantity' => '999']);
        $result = app(StockLedger::class)->reconcile($this->owner, $this->warehouse->id);
        $this->assertFalse($result['balanced']);
        $this->assertSame('10.000', $result['issues'][0]['ledger_quantity']);
    }

    public function test_postgres_blocks_direct_ledger_update(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger test.');
        }$this->postStock();
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->update(['quantity' => '999']);
    }

    public function test_sanctum_inventory_api_and_strict_decimal_input(): void
    {
        $token = $this->owner->createToken('inventory')->plainTextToken;
        $this->getJson('/api/v1/inventory', ['Authorization' => 'Bearer '.$token])->assertOk();
        $d = $this->document();
        $d['lines'][0]['quantity'] = 1.5;
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', $d)->assertUnprocessable();
        $d['lines'][0]['quantity'] = '1.0001';
        $this->postJson('/api/v1/inventory/movements', $d)->assertUnprocessable();
    }
}

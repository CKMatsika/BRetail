<?php

namespace Tests\Feature;

use App\Models\CustomerCredit;
use App\Models\PosCustomer;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\Customers;
use App\Services\Onboarding;
use App\Services\Suppliers;
use App\Services\TenantContext;
use App\Services\TradeLedger;
use App\Services\TradeSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->owner('customers@example.test');
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($this->owner, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
    }

    private function owner(string $email): User
    {
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => $email, 'password' => 'SecurePassword123', 'company_name' => 'Customers Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'sales', 'purchasing', 'pos'])->update(['enabled' => true]);
        DB::table('tenant_subscriptions')->where('tenant_id', $owner->tenant_id)->update(['status' => 'active']);

        return $owner;
    }

    public function test_customer_crud_endpoints_and_credit_setting_cleanup(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson('/trade/customers', ['name' => 'Hande High School', 'email' => 'office@hande.test', 'phone' => '0771234567'])->assertOk()->json('data.id');
        $this->getJson('/trade/customers/'.$id)->assertOk()->assertJsonPath('data.email', 'office@hande.test');
        $this->patchJson('/trade/customers/'.$id, ['name' => 'Hande High School', 'email' => 'accounts@hande.test', 'phone' => '0771234567'])->assertOk()->assertJsonPath('data.email', 'accounts@hande.test');
        app(TenantContext::class)->id = $this->owner->tenant_id;
        app(TradeLedger::class)->credit($this->owner, ['customer_id' => $id, 'credit_limit' => '100', 'terms_days' => 30, 'active' => true]);
        $this->deleteJson('/trade/customers/'.$id)->assertOk();
        $this->getJson('/trade/customers/'.$id)->assertNotFound();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertSame(0, CustomerCredit::count());
        $this->assertSame(0, PosCustomer::count());
    }

    public function test_customer_similarity_and_contact_matches_are_blocked_in_trade_and_pos(): void
    {
        app(Customers::class)->save($this->owner, ['name' => 'Hande High School', 'email' => 'office@hande.test', 'phone' => '0771234567']);
        $this->actingAs($this->owner);
        foreach (['HANDE HIGH SCHOOL', 'Hande-High School', 'Hande High Scool'] as $name) {
            $this->postJson('/trade/customers', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->postJson('/trade/customers', ['name' => 'Another school', 'email' => 'OFFICE@hande.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/trade/customers', ['name' => 'Another school', 'phone' => '077 123 4567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/pos/customers', ['name' => 'Hande High Scool'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/trade/customers', ['name' => 'Beacon Academy'])->assertOk();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertSame(2, PosCustomer::count());
    }

    public function test_edit_ignores_itself_and_rejects_another_matching_customer(): void
    {
        $one = app(Customers::class)->save($this->owner, ['name' => 'Hande High School']);
        $two = app(Customers::class)->save($this->owner, ['name' => 'Beacon Academy']);
        $this->actingAs($this->owner)->patchJson('/trade/customers/'.$one->id, ['name' => 'Hande High School'])->assertOk();
        $this->patchJson('/trade/customers/'.$two->id, ['name' => 'Hande High Scool'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_supplier_similarity_corporate_suffixes_and_pos_create_are_protected(): void
    {
        $data = ['code' => 'SUP1', 'name' => 'Betterlink Industries (Pvt) Ltd', 'terms_days' => 30, 'active' => true];
        $supplier = app(Suppliers::class)->save($this->owner, $data);
        $this->actingAs($this->owner)->postJson('/trade/suppliers', [...$data, 'code' => 'SUP2', 'name' => 'Betterlink Industries Limited'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/trade/suppliers', [...$data, 'code' => 'SUP2', 'name' => 'Betterlink Industriess'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/trade/suppliers/'.$supplier->id, [...$data, 'active' => false])->assertOk();
        $this->postJson('/trade/suppliers', [...$data, 'code' => 'SUP2', 'name' => 'Central Supply'])->assertOk();
    }

    public function test_customer_with_a_quote_cannot_be_deleted(): void
    {
        $customer = app(Customers::class)->save($this->owner, ['name' => 'Hande High School']);
        $product = app(Catalog::class)->product($this->owner, ['name' => 'ERP', 'item_type' => 'service', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $sku = app(Catalog::class)->variant($this->owner, $product, ['name' => 'Standard', 'sku' => 'ERP', 'cost_price' => '0', 'selling_price' => '100', 'wholesale_price' => '100', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        app(TradeSales::class)->quote($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => Warehouse::firstOrFail()->id, 'customer_id' => $customer->id, 'date' => AccountingLedger::today(), 'due_on' => AccountingLedger::today(), 'reference' => 'QT', 'items' => [['variant_id' => $sku->id, 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0']]]);
        $this->actingAs($this->owner)->deleteJson('/trade/customers/'.$customer->id)->assertUnprocessable();
        $this->getJson('/trade/customers/'.$customer->id)->assertOk();
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $customer = app(Customers::class)->save($this->owner, ['name' => 'Hande High School']);
        $foreign = $this->owner('other-customers@example.test');
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($foreign, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $this->actingAs($foreign)->getJson('/trade/customers/'.$customer->id)->assertNotFound();
        $this->patchJson('/trade/customers/'.$customer->id, ['name' => 'Changed'])->assertNotFound();
        $this->deleteJson('/trade/customers/'.$customer->id)->assertNotFound();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $role = $this->owner->role;
        $role->update(['permissions' => ['sales.view']]);
        $this->owner->unsetRelation('role');
        $this->actingAs($this->owner)->getJson('/trade/customers/'.$customer->id)->assertOk();
        $this->patchJson('/trade/customers/'.$customer->id, ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson('/trade/customers/'.$customer->id)->assertForbidden();
    }
}

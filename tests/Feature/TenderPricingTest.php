<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderPricingEstimate;
use App\Models\TenderPricingItem;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private User $readOnlyUser;
    private Tender $tender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $onboarding = app(Onboarding::class);

        $this->owner = $onboarding->register([
            'name' => 'Kudakwashe Chiwenga',
            'email' => 'kuda@industrial-power.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Industrial Power Systems Pvt Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD', 'ZWG'],
            'branch_name' => 'Harare Depot',
            'branch_code' => 'HRE-01',
            'warehouse_name' => 'Main Depot',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
        ]);

        $this->otherOwner = $onboarding->register([
            'name' => 'Sipho Ndlovu',
            'email' => 'sipho@competitor-engineering.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Competitor Engineering Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD', 'ZWG'],
            'branch_name' => 'Bulawayo Plant',
            'branch_code' => 'BYO-01',
            'warehouse_name' => 'Main Warehouse',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
        ]);

        DB::table('tenant_subscriptions')
            ->whereIn('tenant_id', [$this->owner->tenant_id, $this->otherOwner->tenant_id])
            ->update(['status' => 'active']);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Read-only user with Sales Assistant role
        $this->readOnlyUser = new User([
            'name' => 'Tendai Mashingaidze',
            'email' => 'tendai@industrial-power.co.zw',
            'password' => 'SecurePass1234!',
            'active' => true,
        ]);
        $this->readOnlyUser->tenant_id = $this->owner->tenant_id;
        $this->readOnlyUser->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyUser->save();

        // Create tender with estimated budget of $125,000
        $this->tender = Tender::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'reference_number' => 'ZETDC/DOM/044/2026',
            'title' => 'Supply and Delivery of 100kVA Distribution Transformers',
            'procuring_entity' => 'Zimbabwe Electricity Transmission and Distribution Company',
            'entity_type' => 'parastatal',
            'procurement_category' => 'goods',
            'procurement_method' => 'domestic_competitive_bidding',
            'opportunity_type' => 'invitation_to_tender',
            'praz_categories' => ['SP001'],
            'currency' => 'USD',
            'estimated_value' => 125000.00,
            'tender_fee' => 150.00,
            'bid_security_required' => true,
            'bid_security_amount' => 2500.00,
            'closing_at' => Carbon::now()->addDays(20)->toDateTimeString(),
            'submission_method' => 'eprocurement_portal',
            'status' => 'active_bidding',
        ]);
    }

    public function test_guest_cannot_access_pricing_endpoints(): void
    {
        $response = $this->getJson("/tenders/{$this->tender->id}/pricing");
        $response->assertStatus(401);

        $response = $this->postJson("/tenders/{$this->tender->id}/pricing/items", [
            'description' => '100kVA Step-down Transformer',
            'quantity' => 2,
            'unit_cost' => 10000,
        ]);
        $response->assertStatus(401);
    }

    public function test_read_only_user_can_view_but_cannot_modify(): void
    {
        $this->actingAs($this->readOnlyUser);

        // Viewing initializes estimate
        $response = $this->getJson("/tenders/{$this->tender->id}/pricing");
        $response->assertOk();
        $response->assertJsonStructure([
            'estimate' => [
                'id',
                'primary_currency',
                'items',
            ],
            'summary' => [
                'total_bid_price',
                'direct_cost_total',
                'gross_margin_percent',
                'net_profit',
                'break_even_floor_price',
                'competitiveness',
            ],
            'tender',
        ]);

        // Attempting to add item is forbidden
        $response = $this->postJson("/tenders/{$this->tender->id}/pricing/items", [
            'description' => 'High Voltage Insulators',
            'quantity' => 10,
            'unit_cost' => 250,
        ]);
        $response->assertStatus(403);
    }

    public function test_initialization_seeds_boq_and_calculates_summary(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson("/tenders/{$this->tender->id}/pricing");
        $response->assertOk();

        $estimate = $response->json('estimate');
        $this->assertEquals('USD', $estimate['primary_currency']);
        $this->assertNotEmpty($estimate['items']);

        $summary = $response->json('summary');
        $this->assertGreaterThan(0, $summary['direct_cost_total']);
        $this->assertGreaterThan(0, $summary['total_bid_price']);
        $this->assertGreaterThan(0, $summary['break_even_floor_price']);
        $this->assertTrue($summary['competitiveness']['has_budget']);
    }

    public function test_adding_updating_and_deleting_boq_line_item(): void
    {
        $this->actingAs($this->owner);

        // First initialize
        $this->getJson("/tenders/{$this->tender->id}/pricing");

        // 1. Add line item
        $addResp = $this->postJson("/tenders/{$this->tender->id}/pricing/items", [
            'description' => 'Heavy Duty Copper Busbars 100A',
            'category' => 'materials',
            'item_number' => '2.01',
            'unit_of_measure' => 'meters',
            'quantity' => 50,
            'unit_cost' => 80.00,
            'markup_percent' => 20.00,
        ]);

        $addResp->assertStatus(201);
        $itemId = $addResp->json('item.id');
        $this->assertEquals(4000.00, $addResp->json('item.subtotal_cost'));
        $this->assertEquals(96.00, $addResp->json('item.unit_price'));
        $this->assertEquals(4800.00, $addResp->json('item.total_price'));

        // 2. Update line item
        $updateResp = $this->patchJson("/tenders/pricing/items/{$itemId}", [
            'quantity' => 60,
            'unit_cost' => 85.00,
        ]);

        $updateResp->assertOk();
        $this->assertEquals(5100.00, $updateResp->json('item.subtotal_cost'));

        // 3. Delete line item
        $deleteResp = $this->deleteJson("/tenders/pricing/items/{$itemId}");
        $deleteResp->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertNull(TenderPricingItem::find($itemId));
    }

    public function test_updating_financial_assumptions_and_split_currency(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/tenders/{$this->tender->id}/pricing");

        $settingsResp = $this->patchJson("/tenders/{$this->tender->id}/pricing/settings", [
            'target_margin_percent' => 18.00,
            'overheads_percent' => 6.00,
            'exchange_risk_buffer_percent' => 4.00,
            'is_split_currency' => true,
            'foreign_currency' => 'USD',
            'local_currency' => 'ZWG',
            'foreign_split_ratio' => 65.00,
            'local_split_ratio' => 35.00,
            'exchange_rate' => 25.5000,
        ]);

        $settingsResp->assertOk();
        $estimate = $settingsResp->json('estimate');
        $this->assertTrue($estimate['is_split_currency']);
        $this->assertEquals(18.00, $estimate['target_margin_percent']);

        $summary = $settingsResp->json('summary');
        $this->assertTrue($summary['is_split_currency']);
        $this->assertNotNull($summary['split_currency']);
        $this->assertEquals('USD', $summary['split_currency']['foreign_currency']);
        $this->assertEquals('ZWG', $summary['split_currency']['local_currency']);
        $this->assertGreaterThan(0, $summary['split_currency']['local_converted_amount']);
    }

    public function test_signing_off_commercial_bid_pricing(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/tenders/{$this->tender->id}/pricing");

        $approveResp = $this->postJson("/tenders/{$this->tender->id}/pricing/approve", [
            'notes' => 'Commercial sign-off approved by Financial Controller.',
        ]);

        $approveResp->assertOk();
        $estimate = $approveResp->json('estimate');
        $this->assertEquals('approved', $estimate['status']);
        $this->assertEquals($this->owner->id, $estimate['approved_by_user_id']);
        $this->assertNotNull($estimate['approved_at']);
    }

    public function test_exporting_boq_csv(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/tenders/{$this->tender->id}/pricing");

        $response = $this->get("/tenders/{$this->tender->id}/pricing/export-csv");
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('ZETDC/DOM/044/2026', $content);
        $this->assertStringContainsString('GRAND TOTAL BID PRICE', $content);
    }

    public function test_multi_tenant_isolation_for_pricing(): void
    {
        $this->actingAs($this->owner);
        $this->getJson("/tenders/{$this->tender->id}/pricing");

        // Tenant B attempts to access Tenant A's pricing
        $this->actingAs($this->otherOwner);

        $response = $this->getJson("/tenders/{$this->tender->id}/pricing");
        $response->assertStatus(404);

        $response = $this->postJson("/tenders/{$this->tender->id}/pricing/items", [
            'description' => 'Unauthorised line item',
            'quantity' => 1,
            'unit_cost' => 100,
        ]);
        $response->assertStatus(404);
    }
}

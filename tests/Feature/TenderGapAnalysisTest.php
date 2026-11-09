<?php

namespace Tests\Feature;

use App\Models\ComplianceDocument;
use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderGapAnalysis;
use App\Models\TenderTechnicalSpec;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderGapAnalysisTest extends TestCase
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

        // Read-only user with Sales Assistant role (which has tenders.view)
        $this->readOnlyUser = new User([
            'name' => 'Tendai Mashingaidze',
            'email' => 'tendai@industrial-power.co.zw',
            'password' => 'SecurePass1234!',
            'active' => true,
        ]);
        $this->readOnlyUser->tenant_id = $this->owner->tenant_id;
        $this->readOnlyUser->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyUser->save();

        // Create a test tender
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

    public function test_guest_cannot_access_gap_analysis(): void
    {
        $response = $this->getJson("/tenders/{$this->tender->id}/gap-analysis");
        $response->assertStatus(401);

        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $response->assertStatus(401);
    }

    public function test_read_only_user_can_view_but_cannot_evaluate_or_override(): void
    {
        $this->actingAs($this->readOnlyUser);

        // Viewing evaluates or gets existing analysis
        $response = $this->getJson("/tenders/{$this->tender->id}/gap-analysis");
        $response->assertOk();
        $response->assertJsonStructure([
            'gap_analysis' => [
                'id',
                'overall_score',
                'recommendation',
                'category_scores',
                'action_plan',
            ],
            'tender',
        ]);

        // Trying to evaluate manually is forbidden
        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $response->assertStatus(403);

        // Trying to override recommendation is forbidden
        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/override", [
            'override_recommendation' => 'strong_bid',
            'override_notes' => 'Executive approval granted.',
        ]);
        $response->assertStatus(403);
    }

    public function test_gap_analysis_detects_fatal_flaws_when_statutory_docs_missing(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $response->assertOk();

        $data = $response->json('gap_analysis');
        $this->assertEquals('do_not_bid', $data['recommendation']);
        $this->assertFalse($data['is_mandatory_passed']);
        $this->assertGreaterThan(0, $data['fatal_flaws_count']);
        $this->assertNotEmpty($data['fatal_flaws']);
        $this->assertNotEmpty($data['action_plan']);

        // Check action plan contains ZIMRA or PRAZ remediation tasks
        $titles = collect($data['action_plan'])->pluck('title')->implode(' ');
        $this->assertStringContainsString('ZIMRA', $titles);
        $this->assertStringContainsString('PRAZ', $titles);
    }

    public function test_adding_valid_statutory_documents_improves_recommendation(): void
    {
        $this->actingAs($this->owner);

        // Upload valid ZIMRA ITF263
        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'title' => 'ZIMRA Tax Clearance Certificate 2026',
            'type_key' => 'zimra_itf263',
            'category' => 'statutory',
            'issuing_authority' => 'Zimbabwe Revenue Authority',
            'reference_number' => 'ITF263-998877',
            'issue_date' => Carbon::now()->subMonths(1),
            'expiry_date' => Carbon::now()->addMonths(11),
            'file_path' => 'compliance/itf263.pdf',
            'file_name' => 'itf263.pdf',
            'file_size' => 102400,
            'mime_type' => 'application/pdf',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        // Upload valid PRAZ Certificate
        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'title' => 'PRAZ Supplier Registration Certificate 2026',
            'type_key' => 'praz_certificate',
            'category' => 'statutory',
            'issuing_authority' => 'Procurement Regulatory Authority of Zimbabwe',
            'reference_number' => 'PRAZ-2026-REG-7744',
            'issue_date' => Carbon::now()->subMonths(1),
            'expiry_date' => Carbon::now()->addMonths(11),
            'file_path' => 'compliance/praz.pdf',
            'file_name' => 'praz.pdf',
            'file_size' => 102400,
            'mime_type' => 'application/pdf',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        // Upload Company Profile & CR14
        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'title' => 'CR14 Directors & Secretary',
            'type_key' => 'cr14_directors',
            'category' => 'company_registration',
            'issuing_authority' => 'Chief Registrar of Companies',
            'reference_number' => 'CR14-2022-8812',
            'file_path' => 'compliance/cr14.pdf',
            'file_name' => 'cr14.pdf',
            'file_size' => 102400,
            'mime_type' => 'application/pdf',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'title' => 'Certificate of Incorporation',
            'type_key' => 'cert_incorporation',
            'category' => 'company_registration',
            'issuing_authority' => 'Chief Registrar of Companies',
            'reference_number' => 'COI-12345/2020',
            'file_path' => 'compliance/coi.pdf',
            'file_name' => 'coi.pdf',
            'file_size' => 102400,
            'mime_type' => 'application/pdf',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        // Re-evaluate
        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $response->assertOk();

        $data = $response->json('gap_analysis');
        $this->assertEquals(0, $data['fatal_flaws_count']);
        $this->assertTrue($data['is_mandatory_passed']);
        $this->assertNotEquals('do_not_bid', $data['recommendation']);
        $this->assertGreaterThanOrEqual(60, $data['overall_score']);
    }

    public function test_recording_manual_override_with_notes(): void
    {
        $this->actingAs($this->owner);

        // First evaluate
        $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");

        // Record manual override to Strong Bid
        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/override", [
            'override_recommendation' => 'strong_bid',
            'override_notes' => 'Board has approved exceptional fast-track procurement for ZETDC bid.',
        ]);

        $response->assertOk();
        $data = $response->json('gap_analysis');

        $this->assertTrue($data['manual_override']);
        $this->assertEquals('strong_bid', $data['override_recommendation']);
        $this->assertEquals('Board has approved exceptional fast-track procurement for ZETDC bid.', $data['override_notes']);
        $this->assertEquals($this->owner->id, $data['override_by_user_id']);
    }

    public function test_updating_action_plan_item_status(): void
    {
        $this->actingAs($this->owner);

        $evalResponse = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $items = $evalResponse->json('gap_analysis.action_plan');
        $this->assertNotEmpty($items);

        $targetItem = $items[0];

        $updateResponse = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/action-item", [
            'item_id' => $targetItem['id'],
            'status' => 'resolved',
            'notes' => 'Document acquired and pending final verification.',
        ]);

        $updateResponse->assertOk();
        $updatedPlan = $updateResponse->json('gap_analysis.action_plan');
        $matching = collect($updatedPlan)->firstWhere('id', $targetItem['id']);

        $this->assertEquals('resolved', $matching['status']);
        $this->assertEquals('Document acquired and pending final verification.', $matching['notes']);
    }

    public function test_exporting_gap_analysis_csv(): void
    {
        $this->actingAs($this->owner);

        $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");

        $response = $this->get("/tenders/{$this->tender->id}/gap-analysis/export");
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('ZETDC/DOM/044/2026', $content);
        $this->assertStringContainsString('Supply and Delivery of 100kVA Distribution Transformers', $content);
        $this->assertStringContainsString('Task ID', $content);
    }

    public function test_multi_tenant_isolation_for_gap_analysis(): void
    {
        // Tenant A creates analysis
        $this->actingAs($this->owner);
        $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");

        // Tenant B attempts to view or override Tenant A's tender
        $this->actingAs($this->otherOwner);

        $response = $this->getJson("/tenders/{$this->tender->id}/gap-analysis");
        $response->assertStatus(404);

        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/evaluate");
        $response->assertStatus(404);

        $response = $this->postJson("/tenders/{$this->tender->id}/gap-analysis/override", [
            'override_recommendation' => 'strong_bid',
            'override_notes' => 'Attempted breach',
        ]);
        $response->assertStatus(404);
    }
}

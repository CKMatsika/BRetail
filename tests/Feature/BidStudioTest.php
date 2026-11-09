<?php

namespace Tests\Feature;

use App\Models\BidProposal;
use App\Models\BidProposalSection;
use App\Models\BidStandardDocument;
use App\Models\ComplianceDocument;
use App\Models\Role;
use App\Models\Tender;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BidStudioTest extends TestCase
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

        // Create active tender
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
            'closing_at' => Carbon::now()->addDays(20)->toDateTimeString(),
            'submission_method' => 'eprocurement_portal',
            'status' => 'active_bidding',
        ]);
    }

    public function test_guest_cannot_access_bid_proposal_endpoints(): void
    {
        $response = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $response->assertStatus(401);

        $response = $this->postJson("/tenders/{$this->tender->id}/bid-proposal/generate-all");
        $response->assertStatus(401);
    }

    public function test_read_only_user_can_view_but_cannot_generate_or_edit(): void
    {
        $this->actingAs($this->readOnlyUser);

        // Viewing initializes proposal
        $response = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $response->assertOk();
        $response->assertJsonStructure([
            'proposal' => [
                'id',
                'title',
                'bidder_name',
                'status',
                'sections',
                'standard_documents',
            ],
            'tender',
        ]);

        // Attempting to regenerate all is forbidden
        $response = $this->postJson("/tenders/{$this->tender->id}/bid-proposal/generate-all");
        $response->assertStatus(403);
    }

    public function test_initialization_generates_all_10_sections_and_standard_documents(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $response->assertOk();

        $sections = $response->json('proposal.sections');
        $this->assertCount(10, $sections);

        $keys = collect($sections)->pluck('section_key')->toArray();
        $this->assertContains('executive_summary', $keys);
        $this->assertContains('company_overview', $keys);
        $this->assertContains('technical_methodology', $keys);
        $this->assertContains('work_plan', $keys);
        $this->assertContains('team_composition', $keys);
        $this->assertContains('risk_quality_assurance', $keys);
        $this->assertContains('hse_plan', $keys);
        $this->assertContains('mobilisation_plan', $keys);
        $this->assertContains('local_content_plan', $keys);
        $this->assertContains('statutory_declarations', $keys);

        // Standard Returnable Schedules
        $docs = $response->json('proposal.standard_documents');
        $this->assertNotEmpty($docs);
        $docTypes = collect($docs)->pluck('doc_type')->toArray();
        $this->assertContains('bid_submission_letter', $docTypes);
        $this->assertContains('non_collusion_declaration', $docTypes);
        $this->assertContains('manufacturer_authorisation', $docTypes);
    }

    public function test_updating_section_content_and_status(): void
    {
        $this->actingAs($this->owner);

        // First get proposal to ensure sections exist
        $initResp = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $sectionId = $initResp->json('proposal.sections.0.id');

        $updateResp = $this->patchJson("/tenders/bid-proposal/sections/{$sectionId}", [
            'content' => 'Revised Executive Summary with enhanced local warranty provisions.',
            'status' => 'in_review',
            'is_included' => true,
        ]);

        $updateResp->assertOk();
        $this->assertEquals('Revised Executive Summary with enhanced local warranty provisions.', $updateResp->json('section.content'));
        $this->assertEquals('in_review', $updateResp->json('section.status'));
    }

    public function test_regenerating_single_section_with_custom_instructions(): void
    {
        $this->actingAs($this->owner);

        $initResp = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $sectionId = $initResp->json('proposal.sections.0.id');

        $regenResp = $this->postJson("/tenders/bid-proposal/sections/{$sectionId}/regenerate", [
            'instructions' => 'Emphasize 24-month local warranty and free spare parts buffer.',
        ]);

        $regenResp->assertOk();
        $content = $regenResp->json('section.content');
        $this->assertStringContainsString('Special Custom Emphasis: Emphasize 24-month local warranty', $content);
    }

    public function test_updating_standard_returnable_document(): void
    {
        $this->actingAs($this->owner);

        $initResp = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $docId = $initResp->json('proposal.standard_documents.0.id');

        $updateResp = $this->patchJson("/tenders/bid-proposal/documents/{$docId}", [
            'content' => 'Signed Bid Submission Letter for ZETDC Tender.',
            'status' => 'ready_to_sign',
        ]);

        $updateResp->assertOk();
        $this->assertEquals('ready_to_sign', $updateResp->json('document.status'));
    }

    public function test_updating_master_proposal_approval_status(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/tenders/{$this->tender->id}/bid-proposal");

        $statusResp = $this->patchJson("/tenders/{$this->tender->id}/bid-proposal/status", [
            'status' => 'approved',
            'overall_notes' => 'Board approval granted following technical audit.',
        ]);

        $statusResp->assertOk();
        $proposal = $statusResp->json('proposal');
        $this->assertEquals('approved', $proposal['status']);
        $this->assertEquals($this->owner->id, $proposal['approved_by_user_id']);
        $this->assertNotNull($proposal['approved_at']);
    }

    public function test_exporting_printable_html_and_word_document(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/tenders/{$this->tender->id}/bid-proposal");

        // HTML Export
        $htmlResp = $this->get("/tenders/{$this->tender->id}/bid-proposal/export-html");
        $htmlResp->assertOk();
        $htmlResp->assertHeader('content-type', 'text/html; charset=UTF-8');
        $htmlContent = $htmlResp->getContent();
        $this->assertStringContainsString('ZETDC/DOM/044/2026', $htmlContent);
        $this->assertStringContainsString('Executive Summary', $htmlContent);

        // Word Export
        $docResp = $this->get("/tenders/{$this->tender->id}/bid-proposal/export-doc");
        $docResp->assertOk();
        $docResp->assertHeader('content-type', 'application/msword; charset=UTF-8');
        $docContent = $docResp->streamedContent();
        $this->assertStringContainsString('ZETDC/DOM/044/2026', $docContent);
    }

    public function test_multi_tenant_isolation_for_bid_proposals(): void
    {
        $this->actingAs($this->owner);
        $this->getJson("/tenders/{$this->tender->id}/bid-proposal");

        // Tenant B attempts to access Tenant A's proposal
        $this->actingAs($this->otherOwner);

        $response = $this->getJson("/tenders/{$this->tender->id}/bid-proposal");
        $response->assertStatus(404);

        $response = $this->postJson("/tenders/{$this->tender->id}/bid-proposal/generate-all");
        $response->assertStatus(404);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ComplianceDocument;
use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderAnalysis;
use App\Models\TenderDocument;
use App\Models\TenderRequirement;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderAnalysisTest extends TestCase
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
            'name' => 'Farai Chiweshe',
            'email' => 'farai@apexengineering.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Apex Engineering Pvt Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD', 'ZWG'],
            'branch_name' => 'Harare HQ',
            'branch_code' => 'HQ-01',
            'warehouse_name' => 'Central Stores',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
        ]);

        $this->otherOwner = $onboarding->register([
            'name' => 'Nqobizitha Moyo',
            'email' => 'nqobi@bulawayotech.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Bulawayo Tech Supplies Pvt Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD', 'ZWG'],
            'branch_name' => 'Bulawayo Branch',
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

        // Read-only user with tenders.view only
        $this->readOnlyUser = new User([
            'name' => 'Read Only Staff',
            'email' => 'viewer@apexengineering.co.zw',
            'password' => 'SecurePass1234!',
            'active' => true,
        ]);
        $this->readOnlyUser->tenant_id = $this->owner->tenant_id;
        $this->readOnlyUser->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyUser->save();

        // Seed a sample tender
        $this->tender = Tender::create([
            'tenant_id' => $this->owner->tenant_id,
            'reference_number' => 'ZETDC/INT/08/2026',
            'title' => 'Supply and Delivery of 11kV Substation Switchgear',
            'procuring_entity' => 'Zimbabwe Electricity Transmission and Distribution Company',
            'entity_type' => 'parastatal',
            'procurement_category' => 'goods',
            'procurement_method' => 'open_competitive_bidding',
            'opportunity_type' => 'tender',
            'praz_categories' => ['SE001', 'SF001'],
            'currency' => 'USD',
            'estimated_value' => 150000.00,
            'tender_fee' => 20.00,
            'bid_security_required' => true,
            'bid_security_amount' => 3000.00,
            'bid_security_details' => 'Commercial Bank Guarantee Valid 90 Days',
            'closing_at' => Carbon::now()->addDays(20),
            'site_visit_required' => true,
            'site_visit_at' => Carbon::now()->addDays(5),
            'site_visit_location' => 'ZETDC National Control Centre, Harare',
            'submission_method' => 'electronic',
            'status' => 'active_bidding',
            'notes' => 'Mandatory ZIMRA tax clearance, PRAZ registration, and 3 years audited accounts required.',
        ]);
    }

    public function test_guest_cannot_access_analysis_endpoints(): void
    {
        $res = $this->getJson("/tenders/{$this->tender->id}/analysis");
        $res->assertUnauthorized();

        $resRun = $this->postJson("/tenders/{$this->tender->id}/analysis/run");
        $resRun->assertUnauthorized();
    }

    public function test_read_only_user_cannot_trigger_analysis_but_can_view(): void
    {
        $res = $this->actingAs($this->readOnlyUser)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");
        $res->assertForbidden();

        $resView = $this->actingAs($this->readOnlyUser)
            ->getJson("/tenders/{$this->tender->id}/analysis");
        $resView->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_ai_tender_analysis_ingestion_and_requirement_extraction(): void
    {
        // Attach a mock RFP document
        $rfpContent = <<<TXT
ZIMBABWE ELECTRICITY TRANSMISSION & DISTRIBUTION COMPANY
TENDER INVITATION: ZETDC/INT/08/2026
SECTION 1: STATUTORY REQUIREMENTS
1. Bidders must submit a valid ZIMRA Tax Clearance Certificate (ITF263).
2. Current PRAZ Supplier Registration Certificate in category SE001.
3. National Social Security Authority (NSSA) compliance clearance.
4. Certified copy of Certificate of Incorporation and CR14 form.

SECTION 2: FINANCIAL & BID SECURITY
5. Audited financial statements for the past 2 years (2024 and 2025).
6. Bid Security of USD 3,000 in the form of a Commercial Bank Guarantee valid for 90 days.

SECTION 3: TECHNICAL SPECIFICATIONS
7. Switchgear must comply with IEC 62271-200 and SAZ standards.
8. Comprehensive manufacturer warranty of not less than 12 months.
9. At least three traceable reference letters for similar substation projects.
10. Lead project engineer must hold a BSc in Electrical Engineering.

SECTION 4: SUBMISSION & SITE VISIT
11. Mandatory pre-bid site visit at National Control Centre, Harare.
12. Electronic submission via PRAZ eGP portal before 10:00 CAT.
TXT;

        $file = UploadedFile::fake()->createWithContent('ZETDC_Switchgear_RFP.txt', $rfpContent);
        $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/documents", [
                'file' => $file,
                'document_category' => 'tender_rfp',
                'description' => 'Main RFP Document',
            ])->assertOk();

        // Seed Valid Compliance Documents in the Vault for ZIMRA and PRAZ
        app(TenantContext::class)->id = $this->owner->tenant_id;

        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'type_key' => 'zimra_itf263',
            'category' => 'statutory',
            'title' => 'ZIMRA ITF263 Tax Clearance',
            'issuing_authority' => 'Zimbabwe Revenue Authority',
            'reference_number' => 'ITF263-2026-999',
            'issue_date' => Carbon::now()->subMonths(1),
            'expiry_date' => Carbon::now()->addMonths(11),
            'verification_status' => 'valid',
            'file_name' => 'itf263.pdf',
            'file_path' => 'compliance/test/itf263.pdf',
            'is_active' => true,
            'version' => 1,
            'created_by' => $this->owner->id,
        ]);

        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'type_key' => 'praz_supplier_cert',
            'category' => 'statutory',
            'title' => 'PRAZ Supplier Certificate',
            'issuing_authority' => 'PRAZ',
            'reference_number' => 'PRAZ/SE001/2026',
            'issue_date' => Carbon::now()->subMonths(2),
            'expiry_date' => Carbon::now()->addMonths(10),
            'verification_status' => 'valid',
            'file_name' => 'praz_cert.pdf',
            'file_path' => 'compliance/test/praz_cert.pdf',
            'is_active' => true,
            'version' => 1,
            'created_by' => $this->owner->id,
        ]);

        // Run Analysis
        $response = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.analysis.status', 'completed');

        app(TenantContext::class)->id = $this->owner->tenant_id;

        $analysis = TenderAnalysis::where('tender_id', $this->tender->id)->firstOrFail();
        $this->assertGreaterThan(5, $analysis->extracted_count);
        $this->assertGreaterThan(0, $analysis->mandatory_count);
        $this->assertGreaterThan(0, $analysis->statutory_count);
        $this->assertGreaterThan(0, $analysis->technical_count);

        $requirements = TenderRequirement::where('tender_id', $this->tender->id)->get();
        
        // Assert statutory requirements extracted and auto-verified
        $zimraReq = $requirements->firstWhere('category', 'statutory_compliance');
        $this->assertNotNull($zimraReq);
        $this->assertEquals('compliant', $zimraReq->verification_status);

        // Technical specification requirement
        $techReq = $requirements->firstWhere('category', 'technical_specification');
        $this->assertNotNull($techReq);
        $this->assertTrue($techReq->is_mandatory);

        // Bid security requirement
        $secReq = $requirements->firstWhere('category', 'bid_security');
        $this->assertNotNull($secReq);
        $this->assertTrue($secReq->is_mandatory);
    }

    public function test_expired_vault_document_flags_risk_and_disqualification(): void
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Seed an Expired ZIMRA certificate in the vault
        ComplianceDocument::create([
            'tenant_id' => $this->owner->tenant_id,
            'type_key' => 'zimra_itf263',
            'category' => 'statutory',
            'title' => 'ZIMRA ITF263 Tax Clearance',
            'issuing_authority' => 'ZIMRA',
            'reference_number' => 'ITF263-EXPIRED',
            'issue_date' => Carbon::now()->subYears(2),
            'expiry_date' => Carbon::now()->subMonths(3), // Expired
            'verification_status' => 'expired',
            'file_name' => 'expired_itf263.pdf',
            'file_path' => 'compliance/test/expired.pdf',
            'is_active' => true,
            'version' => 1,
            'created_by' => $this->owner->id,
        ]);

        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");

        $res->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $analysis = TenderAnalysis::where('tender_id', $this->tender->id)->firstOrFail();

        // Expired document results in non-compliant status and not_eligible or risk flags
        $this->assertNotEmpty($analysis->key_risks);
        $this->assertStringContainsString('Expired', $analysis->key_risks[0]);

        $zimraReq = TenderRequirement::where('tender_id', $this->tender->id)
            ->where('title', 'like', '%ZIMRA%')
            ->firstOrFail();

        $this->assertEquals('non_compliant', $zimraReq->verification_status);
    }

    public function test_human_review_and_status_updates(): void
    {
        // First run analysis
        $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $req = TenderRequirement::where('tender_id', $this->tender->id)->firstOrFail();

        $updateRes = $this->actingAs($this->owner)
            ->patchJson("/tenders/requirements/{$req->id}", [
                'verification_status' => 'compliant',
                'human_notes' => 'Auditor confirmed certificate is valid on PRAZ portal.',
                'human_verified' => true,
            ]);

        $updateRes->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $req->refresh();

        $this->assertEquals('compliant', $req->verification_status);
        $this->assertEquals('Auditor confirmed certificate is valid on PRAZ portal.', $req->human_notes);
        $this->assertTrue($req->human_verified);
    }

    public function test_adding_and_deleting_custom_requirements(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/requirements", [
                'category' => 'insurance',
                'title' => 'Professional Indemnity Insurance cover of USD 500,000',
                'original_wording' => 'Clause 14.2: Contractor shall maintain PI insurance of $500,000.',
                'simplified_explanation' => 'Proof of active professional indemnity policy with insurance broker.',
                'is_mandatory' => true,
                'evidence_required' => 'Insurance Certificate and Schedule of Cover',
                'verification_status' => 'pending',
                'human_notes' => 'Check with Old Mutual Insurance.',
            ]);

        $res->assertCreated();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $newReq = TenderRequirement::where('tender_id', $this->tender->id)
            ->where('category', 'insurance')
            ->firstOrFail();

        $this->assertEquals('Professional Indemnity Insurance cover of USD 500,000', $newReq->title);
        $this->assertTrue($newReq->is_mandatory);

        // Delete requirement
        $delRes = $this->actingAs($this->owner)
            ->deleteJson("/tenders/requirements/{$newReq->id}");

        $delRes->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertNull(TenderRequirement::find($newReq->id));
    }

    public function test_multi_tenant_isolation_for_tender_analysis(): void
    {
        // Tenant 1 runs analysis
        $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $req = TenderRequirement::where('tender_id', $this->tender->id)->firstOrFail();

        // Tenant 2 attempts to view Tenant 1's analysis
        $resView = $this->actingAs($this->otherOwner)
            ->getJson("/tenders/{$this->tender->id}/analysis");
        $resView->assertNotFound();

        // Tenant 2 attempts to run analysis on Tenant 1's tender
        $resRun = $this->actingAs($this->otherOwner)
            ->postJson("/tenders/{$this->tender->id}/analysis/run");
        $resRun->assertNotFound();

        // Tenant 2 attempts to modify Tenant 1's requirement
        $resUpdate = $this->actingAs($this->otherOwner)
            ->patchJson("/tenders/requirements/{$req->id}", [
                'verification_status' => 'compliant',
            ]);
        $resUpdate->assertNotFound();
    }
}

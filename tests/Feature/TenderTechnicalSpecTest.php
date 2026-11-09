<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderRequirement;
use App\Models\TenderTechnicalSpec;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderTechnicalSpecTest extends TestCase
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
            'branch_name' => 'Bulawayo Branch',
            'branch_code' => 'BYO-01',
            'warehouse_name' => 'Secondary Depot',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
        ]);

        DB::table('tenant_subscriptions')
            ->whereIn('tenant_id', [$this->owner->tenant_id, $this->otherOwner->tenant_id])
            ->update(['status' => 'active']);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        $this->readOnlyUser = new User([
            'name' => 'Read Only Staff',
            'email' => 'viewer@industrial-power.co.zw',
            'password' => 'SecurePass1234!',
            'active' => true,
        ]);
        $this->readOnlyUser->tenant_id = $this->owner->tenant_id;
        $this->readOnlyUser->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyUser->save();

        $this->tender = Tender::create([
            'tenant_id' => $this->owner->tenant_id,
            'reference_number' => 'ZETDC/TX/09/2026',
            'title' => 'Supply and Delivery of 500kVA 33kV Transformers',
            'procuring_entity' => 'ZETDC',
            'entity_type' => 'parastatal',
            'procurement_category' => 'goods',
            'procurement_method' => 'open_competitive_bidding',
            'opportunity_type' => 'tender',
            'currency' => 'USD',
            'closing_at' => Carbon::now()->addDays(25),
            'status' => 'active_bidding',
        ]);
    }

    public function test_guest_cannot_access_technical_specs(): void
    {
        $res = $this->getJson("/tenders/{$this->tender->id}/technical-specs");
        $res->assertUnauthorized();

        $resGen = $this->postJson("/tenders/{$this->tender->id}/technical-specs/generate");
        $resGen->assertUnauthorized();
    }

    public function test_read_only_user_can_view_but_cannot_generate_or_modify(): void
    {
        $resGen = $this->actingAs($this->readOnlyUser)
            ->postJson("/tenders/{$this->tender->id}/technical-specs/generate");
        $resGen->assertForbidden();

        $resView = $this->actingAs($this->readOnlyUser)
            ->getJson("/tenders/{$this->tender->id}/technical-specs");
        $resView->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_generating_technical_compliance_matrix_from_tender(): void
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Seed some technical requirements from previous AI extraction phase
        TenderRequirement::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'category' => 'technical_specification',
            'title' => 'Conformity with IEC 60076 and SAZ 305 Standards',
            'original_wording' => 'Transformers must fully comply with IEC 60076 and SAZ 305 standards.',
            'simplified_explanation' => 'Certified conformity with transformer manufacturing specifications.',
            'is_mandatory' => true,
            'evidence_required' => 'Type Test Reports from an accredited laboratory (KEMA or SABS).',
        ]);

        TenderRequirement::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'category' => 'technical_specification',
            'title' => 'Loss Guarantees and Impedance Ratings',
            'original_wording' => 'No-load losses shall not exceed 1.2 kW, full-load losses max 7.5 kW at 75°C.',
            'simplified_explanation' => 'Guaranteed maximum electrical power losses during operation.',
            'is_mandatory' => true,
            'evidence_required' => 'Design calculation sheets and manufacturer certified test certificates.',
        ]);

        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/technical-specs/generate");

        $res->assertOk()
            ->assertJsonPath('success', true);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        $specs = TenderTechnicalSpec::where('tender_id', $this->tender->id)->get();
        $this->assertCount(2, $specs);

        $spec1 = $specs->first();
        $this->assertEquals('TS-01', $spec1->item_number);
        $this->assertEquals('comply', $spec1->compliance_status);
        $this->assertTrue($spec1->is_mandatory);
        $this->assertNotNull($spec1->bidder_offered_specification);
    }

    public function test_updating_technical_specification_and_recording_deviation(): void
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;

        $spec = TenderTechnicalSpec::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'item_number' => 'TS-01',
            'title' => 'Cooling Method and Radiator Type',
            'original_specification' => 'Cooling shall be ONAN (Oil Natural Air Natural) with detachable pressed steel radiators.',
            'is_mandatory' => true,
            'compliance_status' => 'comply',
            'bidder_offered_specification' => 'ONAN cooling with detachable steel radiators.',
        ]);

        // Bidder modifies specification to record an allowed minor deviation
        $res = $this->actingAs($this->owner)
            ->patchJson("/tenders/technical-specs/{$spec->id}", [
                'compliance_status' => 'comply_with_deviation',
                'bidder_offered_specification' => 'Corrugated tank ONAN cooling offering superior heat dissipation to conventional radiators.',
                'deviation_statement' => 'Corrugated tank design utilized instead of detachable radiators; fully tested and compliant with cooling temperature rise limit of 50K.',
                'evidence_reference' => 'Technical Brochure Page 14, Annexure C',
                'human_verified' => true,
            ]);

        $res->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $spec->refresh();

        $this->assertEquals('comply_with_deviation', $spec->compliance_status);
        $this->assertEquals('Technical Brochure Page 14, Annexure C', $spec->evidence_reference);
        $this->assertStringContainsString('Corrugated tank', $spec->deviation_statement);
        $this->assertTrue($spec->human_verified);

        // Verify summary identifies the mandatory deviation
        $listRes = $this->actingAs($this->owner)
            ->getJson("/tenders/{$this->tender->id}/technical-specs");

        $listRes->assertOk();
        $this->assertEquals(1, $listRes->json('data.summary.deviation_count'));
        $this->assertCount(1, $listRes->json('data.summary.mandatory_deviations'));
    }

    public function test_adding_and_deleting_custom_technical_clause(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/technical-specs", [
                'item_number' => 'TS-05',
                'title' => 'Buchholz Relay and Temperature Monitoring',
                'original_specification' => 'Fitted with double-float Buchholz relay and dial-type oil thermometer with alarm and trip contacts.',
                'plain_language_explanation' => 'Protection relay to detect gas accumulation and internal transformer faults.',
                'purpose' => 'Substation fault protection and remote tripping.',
                'is_mandatory' => true,
                'required_evidence' => 'Relay datasheet and wiring schematic.',
                'suggested_solution' => 'Qualitrol double-float Buchholz relay conforming to DIN 42566.',
                'compliance_status' => 'comply',
                'evidence_reference' => 'Annexure D, Page 4',
            ]);

        $res->assertCreated();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $spec = TenderTechnicalSpec::where('tender_id', $this->tender->id)
            ->where('item_number', 'TS-05')
            ->firstOrFail();

        $this->assertEquals('Buchholz Relay and Temperature Monitoring', $spec->title);

        // Delete clause
        $delRes = $this->actingAs($this->owner)
            ->deleteJson("/tenders/technical-specs/{$spec->id}");

        $delRes->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertNull(TenderTechnicalSpec::find($spec->id));
    }

    public function test_exporting_technical_compliance_matrix_as_csv(): void
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;

        TenderTechnicalSpec::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'item_number' => 'TS-01',
            'title' => 'Rated Capacity 500kVA',
            'original_specification' => 'Continuous rated capacity 500kVA at 50Hz.',
            'purpose' => 'Load capacity',
            'is_mandatory' => true,
            'suggested_solution' => '500kVA 50Hz continuous copper winding',
            'bidder_offered_specification' => '500kVA 50Hz continuous copper winding',
            'compliance_status' => 'comply',
            'evidence_reference' => 'Brochure Page 2',
        ]);

        $response = $this->actingAs($this->owner)
            ->get("/tenders/{$this->tender->id}/technical-specs/export");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('SCHEDULE OF TECHNICAL COMPLIANCE', $content);
        $this->assertStringContainsString('ZETDC/TX/09/2026', $content);
        $this->assertStringContainsString('TS-01', $content);
        $this->assertStringContainsString('MANDATORY', $content);
        $this->assertStringContainsString('COMPLY', $content);
    }

    public function test_multi_tenant_isolation_for_technical_specs(): void
    {
        app(TenantContext::class)->id = $this->owner->tenant_id;

        $spec = TenderTechnicalSpec::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'item_number' => 'TS-01',
            'title' => 'Tenant 1 Spec',
            'original_specification' => 'Secret specification.',
            'compliance_status' => 'comply',
        ]);

        // Tenant 2 cannot list Tenant 1's specs
        $resList = $this->actingAs($this->otherOwner)
            ->getJson("/tenders/{$this->tender->id}/technical-specs");
        $resList->assertNotFound();

        // Tenant 2 cannot generate specs for Tenant 1's tender
        $resGen = $this->actingAs($this->otherOwner)
            ->postJson("/tenders/{$this->tender->id}/technical-specs/generate");
        $resGen->assertNotFound();

        // Tenant 2 cannot update Tenant 1's spec
        $resUpdate = $this->actingAs($this->otherOwner)
            ->patchJson("/tenders/technical-specs/{$spec->id}", [
                'compliance_status' => 'not_comply',
            ]);
        $resUpdate->assertNotFound();

        // Tenant 2 cannot delete Tenant 1's spec
        $resDel = $this->actingAs($this->otherOwner)
            ->deleteJson("/tenders/technical-specs/{$spec->id}");
        $resDel->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tender;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderSubmissionAndAnalyticsTest extends TestCase
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
            'email' => 'kuda@submission-power.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Submission Power Systems Pvt Ltd',
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
            'email' => 'sipho@competitor-submissions.co.zw',
            'password' => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'company_name' => 'Competitor Submissions Ltd',
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
            'email' => 'tendai@submission-power.co.zw',
            'password' => 'SecurePass1234!',
            'active' => true,
        ]);
        $this->readOnlyUser->tenant_id = $this->owner->tenant_id;
        $this->readOnlyUser->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyUser->save();

        $this->tender = Tender::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'reference_number' => 'PRAZ/ENG/2026/099',
            'title' => 'Harare Solar Plant Grid Integration',
            'procuring_entity' => 'ZETDC Parastatal',
            'entity_type' => 'parastatal',
            'procurement_category' => 'works',
            'procurement_method' => 'open_competitive_bidding',
            'currency' => 'USD',
            'estimated_value' => 850000.00,
            'closing_at' => now()->addDays(20),
            'status' => 'active_bidding',
        ]);
    }

    public function test_guest_cannot_access_submission_or_analytics_endpoints(): void
    {
        $this->getJson("/tenders/{$this->tender->id}/submissions")->assertUnauthorized();
        $this->postJson("/tenders/{$this->tender->id}/approvals", [])->assertUnauthorized();
        $this->postJson("/tenders/{$this->tender->id}/submissions", [])->assertUnauthorized();
        $this->getJson("/tenders/analytics/dashboard")->assertUnauthorized();
        $this->postJson("/tenders/{$this->tender->id}/outcome", [])->assertUnauthorized();
    }

    public function test_read_only_user_cannot_sign_off_or_submit(): void
    {
        $this->actingAs($this->readOnlyUser)
            ->postJson("/tenders/{$this->tender->id}/approvals", [
                'stage' => 'technical',
                'status' => 'approved',
            ])
            ->assertForbidden();

        $this->actingAs($this->readOnlyUser)
            ->postJson("/tenders/{$this->tender->id}/submissions", [
                'submission_channel' => 'praz_egp',
                'egp_reference_number' => 'EGP-999-123',
            ])
            ->assertForbidden();
    }

    public function test_initialization_of_three_tier_approval_stages_and_readiness_checklist(): void
    {
        $res = $this->actingAs($this->owner)
            ->getJson("/tenders/{$this->tender->id}/submissions")
            ->assertOk();

        $approvals = $res->json('approvals');
        $this->assertCount(3, $approvals);

        $stages = collect($approvals)->pluck('stage')->all();
        $this->assertContains('technical', $stages);
        $this->assertContains('commercial', $stages);
        $this->assertContains('executive', $stages);

        $readiness = $res->json('readiness');
        $this->assertFalse($readiness['is_ready']);
        $this->assertArrayHasKey('checklist', $readiness);
    }

    public function test_recording_approval_stage_decisions(): void
    {
        // 1. Technical stage approved
        $techRes = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/approvals", [
                'stage' => 'technical',
                'status' => 'approved',
                'comments' => 'All IEC technical standards and single-line diagrams verified compliant.',
            ])
            ->assertOk();

        $this->assertEquals('approved', $techRes->json('approval.status'));
        $this->assertEquals('technical', $techRes->json('approval.stage'));

        // 2. Commercial stage approved
        $commRes = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/approvals", [
                'stage' => 'commercial',
                'status' => 'approved',
                'comments' => 'Profit margin at 18.5%, PRAZ 1% statutory levy budgeted.',
            ])
            ->assertOk();

        $this->assertEquals('approved', $commRes->json('approval.status'));

        // 3. Executive stage approved with conditions
        $execRes = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/approvals", [
                'stage' => 'executive',
                'status' => 'approved',
                'comments' => 'Authorized by Managing Director for submission.',
                'conditions' => 'Ensure original bank guarantee is hand-delivered to tender box.',
            ])
            ->assertOk();

        $this->assertEquals('approved', $execRes->json('approval.status'));
        $this->assertNotNull($execRes->json('approval.actioned_at'));
    }

    public function test_recording_official_bid_submission(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/submissions", [
                'submission_channel' => 'praz_egp',
                'egp_reference_number' => 'EGP-ZETDC-2026-888',
                'delivered_by_name' => 'Tinashe Chikwanha',
                'delivered_by_phone' => '+263 77 123 4567',
                'receipt_number' => 'REC-PRAZ-77112',
                'technical_envelope_verified' => true,
                'financial_envelope_verified' => true,
                'bid_security_verified' => true,
                'copies_count' => 3,
                'package_notes' => 'Encrypted electronic bid submitted via eGP and duplicate physical copy deposited in tender box #2',
            ])
            ->assertOk();

        $this->assertEquals('submitted', $res->json('tender_status'));
        $this->assertEquals('EGP-ZETDC-2026-888', $res->json('submission.egp_reference_number'));

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->tender->refresh();
        $this->assertEquals('submitted', $this->tender->status);
    }

    public function test_recording_public_tender_opening_minutes_and_competitor_bids(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/opening-minutes", [
                'attended_by_name' => 'Eng. Munyaradzi Moyo',
                'opening_date' => now()->format('Y-m-d H:i:s'),
                'venue' => 'ZETDC Electricity Centre, 2nd Floor Boardroom, Harare',
                'minutes_notes' => 'Tender opened at 10:15 AM in presence of 4 participating bidders.',
                'competitor_bids' => [
                    [
                        'competitor_name' => 'SolarTech Africa Ltd',
                        'currency' => 'USD',
                        'bid_amount' => 895000.00,
                        'bid_security_provided' => true,
                        'remarks' => 'Bid bond valid for 90 days from CBZ Bank',
                    ],
                    [
                        'competitor_name' => 'ZimGrid Dynamics Pvt Ltd',
                        'currency' => 'USD',
                        'bid_amount' => 810000.00,
                        'bid_security_provided' => true,
                        'remarks' => 'Lowest read out price, pending technical evaluation',
                    ],
                ],
            ])
            ->assertOk();

        $opening = $res->json('opening_record');
        $this->assertEquals('Eng. Munyaradzi Moyo', $opening['attended_by_name']);
        $this->assertCount(2, $opening['competitor_bids']);
        $this->assertEquals('SolarTech Africa Ltd', $opening['competitor_bids'][0]['competitor_name']);
    }

    public function test_recording_tender_outcome_and_status_update(): void
    {
        // 1. Record win (awarded)
        $res = $this->actingAs($this->owner)
            ->postJson("/tenders/{$this->tender->id}/outcome", [
                'outcome' => 'awarded',
                'winning_bidder_name' => 'Submission Power Systems Pvt Ltd',
                'winning_price' => 840000.00,
                'winning_currency' => 'USD',
                'contract_reference' => 'ZETDC/CON/2026/04',
                'debrief_notes' => 'Awarded based on highest combined technical and financial score (92.4%).',
                'final_margin_percent' => 19.2,
            ])
            ->assertOk();

        $this->assertEquals('awarded', $res->json('tender_status'));
        $this->assertEquals('awarded', $res->json('outcome.outcome'));
        $this->assertEquals('ZETDC/CON/2026/04', $res->json('outcome.contract_reference'));

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->tender->refresh();
        $this->assertEquals('awarded', $this->tender->status);

        // 2. Fetch outcome
        $showRes = $this->actingAs($this->owner)
            ->getJson("/tenders/{$this->tender->id}/outcome")
            ->assertOk();

        $this->assertEquals('awarded', $showRes->json('outcome.outcome'));
    }

    public function test_executive_dashboard_metrics_and_analytics(): void
    {
        // Create an additional unsuccessful tender
        $lostTender = Tender::create([
            'tenant_id' => $this->owner->tenant_id,
            'created_by_user_id' => $this->owner->id,
            'reference_number' => 'MOHCC/MED/2026/012',
            'title' => 'Supply of Hospital Laboratory Reagents',
            'procuring_entity' => 'Ministry of Health and Child Care',
            'entity_type' => 'government',
            'procurement_category' => 'goods',
            'currency' => 'USD',
            'estimated_value' => 200000.00,
            'closing_at' => now()->subDays(5),
            'status' => 'unsuccessful',
        ]);

        $this->actingAs($this->owner)
            ->postJson("/tenders/{$lostTender->id}/outcome", [
                'outcome' => 'lost',
                'winning_bidder_name' => 'MedPharm Distributors Pvt Ltd',
                'winning_price' => 185000.00,
                'loss_reason' => 'price_higher',
                'debrief_notes' => 'Lost on financial score; competitor was 7.5% cheaper.',
            ])
            ->assertOk();

        // Check dashboard analytics
        $metricsRes = $this->actingAs($this->owner)
            ->getJson("/tenders/analytics/dashboard")
            ->assertOk();

        $data = $metricsRes->json();
        $this->assertArrayHasKey('total_tenders', $data);
        $this->assertArrayHasKey('win_rate_percent', $data);
        $this->assertArrayHasKey('entity_type_breakdown', $data);
        $this->assertArrayHasKey('loss_reasons', $data);
        $this->assertArrayHasKey('top_competitors', $data);

        // MedPharm should be in top competitors
        $this->assertArrayHasKey('MedPharm Distributors Pvt Ltd', $data['top_competitors']);
        $this->assertEquals(1, $data['loss_reasons']['price_higher']);
    }

    public function test_exporting_complete_audit_trail_csv(): void
    {
        $res = $this->actingAs($this->owner)
            ->get("/tenders/{$this->tender->id}/audit-export");

        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $res->streamedContent();

        $this->assertStringContainsString('TENDER MASTER AUDIT TRAIL', $content);
        $this->assertStringContainsString('PRAZ/ENG/2026/099', $content);
        $this->assertStringContainsString('INTERNAL APPROVAL SIGN-OFFS', $content);
    }

    public function test_multi_tenant_isolation_for_submissions_and_approvals(): void
    {
        $this->actingAs($this->otherOwner)
            ->getJson("/tenders/{$this->tender->id}/submissions")
            ->assertNotFound();

        $this->actingAs($this->otherOwner)
            ->postJson("/tenders/{$this->tender->id}/approvals", [
                'stage' => 'technical',
                'status' => 'approved',
            ])
            ->assertNotFound();

        $this->actingAs($this->otherOwner)
            ->postJson("/tenders/{$this->tender->id}/outcome", [
                'outcome' => 'awarded',
            ])
            ->assertNotFound();
    }
}

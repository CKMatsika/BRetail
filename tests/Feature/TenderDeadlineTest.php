<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderAlert;
use App\Models\TenderClarification;
use App\Models\TenderMilestone;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderDeadlineTest extends TestCase
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

        // Create active bidding tender with closing in 15 days
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
            'closing_at' => Carbon::now()->addDays(15)->setTime(10, 0)->toDateTimeString(),
            'clarification_deadline' => Carbon::now()->addDays(5)->setTime(16, 0)->toDateTimeString(),
            'site_visit_required' => true,
            'site_visit_at' => Carbon::now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
            'site_visit_location' => 'ZETDC Harare Central Substation, Coventry Road',
            'internal_deadline' => Carbon::now()->addDays(10)->setTime(17, 0)->toDateTimeString(),
            'submission_method' => 'eprocurement_portal',
            'status' => 'active_bidding',
        ]);
    }

    public function test_guest_cannot_access_milestones(): void
    {
        $response = $this->getJson("/tenders/{$this->tender->id}/milestones");
        $response->assertStatus(401);

        $response = $this->postJson("/tenders/{$this->tender->id}/milestones", [
            'title' => 'Site Visit Preparation',
            'type' => 'custom',
            'due_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ]);
        $response->assertStatus(401);
    }

    public function test_read_only_user_can_view_but_cannot_create_or_modify_milestones(): void
    {
        $this->actingAs($this->readOnlyUser);

        // Viewing index auto-generates default milestones
        $response = $this->getJson("/tenders/{$this->tender->id}/milestones");
        $response->assertOk();
        $response->assertJsonStructure([
            'tender',
            'schedule' => [
                'total',
                'completed_count',
                'at_risk_count',
                'missed_count',
                'pending_count',
                'milestones',
            ],
            'clarifications',
        ]);

        // Creating custom milestone is forbidden
        $response = $this->postJson("/tenders/{$this->tender->id}/milestones", [
            'title' => 'Internal Pre-Bid Meeting',
            'type' => 'custom',
            'due_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ]);
        $response->assertStatus(403);
    }

    public function test_automatic_generation_of_default_zimbabwean_milestones(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson("/tenders/{$this->tender->id}/milestones");
        $response->assertOk();

        $milestones = $response->json('schedule.milestones');
        $this->assertNotEmpty($milestones);

        $types = collect($milestones)->pluck('type')->toArray();
        $this->assertContains('submission_closing', $types);
        $this->assertContains('pricing_signoff', $types);
        $this->assertContains('technical_review', $types);
        $this->assertContains('internal_draft', $types);
        $this->assertContains('clarification_deadline', $types);
        $this->assertContains('site_visit', $types);
        $this->assertContains('bid_security_expiry', $types);
        $this->assertContains('tender_validity_expiry', $types);
    }

    public function test_creating_and_completing_custom_milestone(): void
    {
        $this->actingAs($this->owner);

        $createResponse = $this->postJson("/tenders/{$this->tender->id}/milestones", [
            'title' => 'Obtain Bank Letter of Credit for Bid Security',
            'type' => 'custom',
            'description' => 'Coordinate with Standard Chartered Bank for $2500 bid bond facility.',
            'due_at' => Carbon::now()->addDays(4)->setTime(15, 0)->toDateTimeString(),
            'venue_or_location' => 'Standard Chartered Africa Unity Square',
        ]);

        $createResponse->assertStatus(201);
        $milestoneId = $createResponse->json('milestone.id');
        $this->assertNotNull($milestoneId);

        // Mark milestone as completed
        $updateResponse = $this->patchJson("/tenders/milestones/{$milestoneId}", [
            'status' => 'completed',
        ]);

        $updateResponse->assertOk();
        $updated = $updateResponse->json('milestone');
        $this->assertEquals('completed', $updated['status']);
        $this->assertNotNull($updated['completed_at']);
        $this->assertEquals($this->owner->id, $updated['completed_by_user_id']);
    }

    public function test_recording_addendum_with_closing_date_extension_recalibrates_tender(): void
    {
        $this->actingAs($this->owner);

        $newClosingDate = Carbon::now()->addDays(25)->setTime(10, 0)->toDateTimeString();

        $response = $this->postJson("/tenders/{$this->tender->id}/clarifications", [
            'type' => 'addendum_received',
            'reference_or_number' => 'Addendum No. 1',
            'subject' => 'Extension of Bid Closing Date & Revised BOQ',
            'question_or_content' => 'Procuring entity has issued Addendum 1 extending the tender closing date by 10 days.',
            'submitted_or_received_at' => Carbon::now()->toDateTimeString(),
            'status' => 'acknowledged',
            'affects_closing_date' => true,
            'new_closing_date' => $newClosingDate,
            'affects_technical_specs' => true,
        ]);

        $response->assertStatus(201);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Verify tender closing_at was updated
        $this->tender->refresh();
        $this->assertEquals($newClosingDate, $this->tender->closing_at);

        // Verify closing milestone was shifted
        $closingMilestone = TenderMilestone::where('tender_id', $this->tender->id)
            ->where('type', 'submission_closing')
            ->first();
        $this->assertNotNull($closingMilestone);
        $this->assertEquals(Carbon::parse($newClosingDate)->toDateTimeString(), $closingMilestone->due_at->toDateTimeString());

        // Verify high-priority in-app alert was recorded
        $alert = TenderAlert::where('tender_id', $this->tender->id)->latest('id')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('Tender Closing Date Extended', $alert->title);
    }

    public function test_deadline_scan_engine_generates_alerts_across_lead_times(): void
    {
        $this->actingAs($this->owner);

        // Create milestone due in 18 hours (trigger 24h warning)
        $m1 = TenderMilestone::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'type' => 'custom',
            'title' => 'Submit Environmental Clearance to City Council',
            'due_at' => Carbon::now()->addHours(18),
            'status' => 'pending',
            'reminder_lead_times' => ['7d', '3d', '24h', '2h'],
            'reminders_sent' => [],
        ]);

        // Create milestone due in 1 hour (trigger 2h urgent warning)
        $m2 = TenderMilestone::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'type' => 'custom',
            'title' => 'Print Final Bound Proposal Documents',
            'due_at' => Carbon::now()->addHour(),
            'status' => 'pending',
            'reminder_lead_times' => ['7d', '3d', '24h', '2h'],
            'reminders_sent' => [],
        ]);

        // Dispatch alerts via controller / engine
        $dispatchResp = $this->postJson('/tenders/alerts/dispatch-now');
        $dispatchResp->assertOk();
        $this->assertGreaterThanOrEqual(2, $dispatchResp->json('alerts_dispatched'));

        // Check alerts endpoint
        $alertsResp = $this->getJson('/tenders/alerts');
        $alertsResp->assertOk();
        $unreadCount = $alertsResp->json('unread_count');
        $this->assertGreaterThan(0, $unreadCount);

        // Running dispatch again should NOT create duplicates for the same thresholds
        $dispatchAgain = $this->postJson('/tenders/alerts/dispatch-now');
        $this->assertEquals(0, $dispatchAgain->json('alerts_dispatched'));
    }

    public function test_marking_alerts_as_read(): void
    {
        $this->actingAs($this->owner);

        $alert = TenderAlert::create([
            'tenant_id' => $this->owner->tenant_id,
            'tender_id' => $this->tender->id,
            'level' => 'warning',
            'title' => 'Urgent Clarification Required',
            'message' => 'Please confirm query before 16:00.',
            'channel' => 'in_app',
            'sent_at' => now(),
            'is_read' => false,
        ]);

        $response = $this->postJson("/tenders/alerts/{$alert->id}/read");
        $response->assertOk();

        $this->assertTrue($alert->fresh()->is_read);

        // Mark all as read
        $readAllResp = $this->postJson('/tenders/alerts/read-all');
        $readAllResp->assertOk();
    }

    public function test_multi_tenant_isolation_for_milestones_and_alerts(): void
    {
        $this->actingAs($this->owner);
        $this->getJson("/tenders/{$this->tender->id}/milestones");

        // Tenant B attempts to access Tenant A's tender milestones
        $this->actingAs($this->otherOwner);

        $response = $this->getJson("/tenders/{$this->tender->id}/milestones");
        $response->assertStatus(404);

        $response = $this->postJson("/tenders/{$this->tender->id}/milestones", [
            'title' => 'Malicious Milestone',
            'type' => 'custom',
            'due_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ]);
        $response->assertStatus(404);
    }
}

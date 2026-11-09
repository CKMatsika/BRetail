<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private User $officer;
    private User $readOnlyAssistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $onboarding = app(Onboarding::class);

        // Tenant A
        $this->owner = $onboarding->register([
            'name' => 'Tatenda Mutasa',
            'email' => 'tatenda@bids.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'company_name' => 'Highveld Solutions Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['ZWG', 'USD'],
            'branch_name' => 'Harare HQ',
            'branch_code' => 'HRE-01',
            'warehouse_name' => 'Central Depot',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
            'invoice_prefix' => 'HVD',
        ]);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Officer with tenders.manage
        $this->officer = new User([
            'name' => 'Brian Moyo',
            'email' => 'brian@bids.test',
            'password' => 'SecurePassword123',
        ]);
        $this->officer->tenant_id = $this->owner->tenant_id;
        $this->officer->role_id = Role::where('name', 'Purchasing Officer')->sole()->id;
        $this->officer->save();

        // Read-only Sales Assistant with tenders.view
        $this->readOnlyAssistant = new User([
            'name' => 'Ruvimbo Chitepo',
            'email' => 'ruvimbo@bids.test',
            'password' => 'SecurePassword123',
        ]);
        $this->readOnlyAssistant->tenant_id = $this->owner->tenant_id;
        $this->readOnlyAssistant->role_id = Role::where('name', 'Sales Assistant')->sole()->id;
        $this->readOnlyAssistant->save();

        // Tenant B (Competitor)
        $this->otherOwner = $onboarding->register([
            'name' => 'Competitor Owner',
            'email' => 'competitor@other.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'company_name' => 'Vanguard Procurement Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD'],
            'branch_name' => 'Bulawayo',
            'branch_code' => 'BYO-01',
            'warehouse_name' => 'Byo Store',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
            'invoice_prefix' => 'VNG',
        ]);

        // Activate subscriptions so middleware permits access
        DB::table('tenant_subscriptions')
            ->whereIn('tenant_id', [$this->owner->tenant_id, $this->otherOwner->tenant_id])
            ->update(['status' => 'active']);

        app(TenantContext::class)->id = $this->owner->tenant_id;
    }

    public function test_guest_cannot_access_tenders(): void
    {
        $this->getJson('/tenders/data')->assertUnauthorized();
        $this->postJson('/tenders', [])->assertUnauthorized();
    }

    public function test_listing_tenders_and_procurement_catalog(): void
    {
        $resp = $this->actingAs($this->owner)->getJson('/tenders/data');

        $resp->assertOk();
        $data = $resp->json('data');

        $this->assertArrayHasKey('tenders', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('catalog', $data);

        $catalog = $data['catalog'];
        $this->assertNotEmpty($catalog['categories']);
        $this->assertNotEmpty($catalog['methods']);
        $this->assertNotEmpty($catalog['praz_categories']);
        $this->assertNotEmpty($catalog['procuring_entity_presets']);

        $categoryKeys = array_column($catalog['categories'], 'key');
        $this->assertContains('goods', $categoryKeys);
        $this->assertContains('works', $categoryKeys);
        $this->assertContains('energy', $categoryKeys);
        $this->assertContains('ict', $categoryKeys);

        $methodKeys = array_column($catalog['methods'], 'key');
        $this->assertContains('open_competitive_bidding', $methodKeys);
        $this->assertContains('rfq', $methodKeys);
    }

    public function test_creating_tender_opportunity_with_documents(): void
    {
        $closingDate = Carbon::today()->addDays(14)->setTime(10, 0)->toIso8601String();
        $file = UploadedFile::fake()->create('zetdc_solar_rfp.pdf', 500, 'application/pdf');

        $payload = [
            'reference_number' => 'ZETDC/DOM/05/2026',
            'title' => 'Supply and Delivery of 100kW Solar Inverter Systems',
            'procuring_entity' => 'ZETDC / ZESA Holdings',
            'entity_type' => 'parastatal',
            'procurement_category' => 'energy',
            'procurement_method' => 'open_competitive_bidding',
            'opportunity_type' => 'tender',
            'praz_categories' => ['SE001', 'SE002'],
            'unspsc_code' => '39121000',
            'currency' => 'USD',
            'estimated_value' => '150000.00',
            'tender_fee' => '50.00',
            'bid_security_required' => true,
            'bid_security_amount' => '3000.00',
            'bid_security_details' => 'Bid bond from a registered commercial bank or PRAZ refundable receipt.',
            'closing_at' => $closingDate,
            'submission_method' => 'electronic_portal',
            'submission_address' => 'https://eprocure.zetdc.co.zw',
            'source_url' => 'https://zetdc.co.zw/tenders/05-2026',
            'contact_person' => 'Procurement Manager',
            'contact_email' => 'procurement@zetdc.co.zw',
            'status' => 'active_bidding',
            'notes' => 'Mandatory pre-bid conference on Day 5 via Teams.',
            'documents' => [$file],
        ];

        $resp = $this->actingAs($this->owner)->postJson('/tenders', $payload);
        $resp->assertOk();

        $tenderId = $resp->json('tender.id');
        $this->assertNotNull($tenderId);

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $tender = Tender::with('documents')->find($tenderId);

        $this->assertNotNull($tender);
        $this->assertSame('ZETDC/DOM/05/2026', $tender->reference_number);
        $this->assertSame('150000.00', $tender->estimated_value);
        $this->assertTrue($tender->bid_security_required);
        $this->assertCount(1, $tender->documents);

        $doc = $tender->documents->first();
        $this->assertSame('zetdc_solar_rfp.pdf', $doc->file_name);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
    }

    public function test_tender_detail_and_document_download(): void
    {
        $tender = new Tender([
            'reference_number' => 'COH/WW/01/2026',
            'title' => 'Water Treatment Chemicals Supply',
            'procuring_entity' => 'City of Harare',
            'entity_type' => 'local_authority',
            'procurement_category' => 'goods',
            'procurement_method' => 'rfq',
            'closing_at' => Carbon::tomorrow()->toIso8601String(),
            'currency' => 'USD',
            'status' => 'active_bidding',
        ]);
        $tender->save();

        $file = UploadedFile::fake()->create('coh_specs.pdf', 300, 'application/pdf');
        $storedPath = $file->storeAs('tenders/'.$this->owner->tenant_id.'/'.$tender->id, 'coh_specs.pdf', 'local');

        $doc = new TenderDocument([
            'tender_id' => $tender->id,
            'file_path' => $storedPath,
            'file_name' => 'coh_specs.pdf',
            'file_size' => 300,
            'mime_type' => 'application/pdf',
            'file_hash' => hash('sha256', 'dummy'),
            'document_type' => 'specifications_boq',
        ]);
        $doc->save();

        // View detail endpoint
        $detailResp = $this->actingAs($this->owner)->getJson('/tenders/'.$tender->id);
        $detailResp->assertOk();
        $this->assertSame('COH/WW/01/2026', $detailResp->json('data.tender.reference_number'));
        $this->assertCount(1, $detailResp->json('data.documents'));

        // Download document endpoint
        $downloadResp = $this->actingAs($this->owner)->get('/tenders/documents/'.$doc->id.'/download');
        $downloadResp->assertOk();
    }

    public function test_tender_update_and_status_transitions(): void
    {
        $tender = new Tender([
            'reference_number' => 'NETONE/ICT/09',
            'title' => 'Tower Batteries Maintenance',
            'procuring_entity' => 'NetOne Cellular',
            'procurement_category' => 'energy',
            'closing_at' => Carbon::today()->addDays(5)->toIso8601String(),
            'status' => 'draft',
        ]);
        $tender->save();

        // Update fields
        $resp = $this->actingAs($this->owner)->patchJson('/tenders/'.$tender->id, [
            'estimated_value' => '45000.00',
            'status' => 'active_bidding',
            'notes' => 'Management approved bidding.',
        ]);
        $resp->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $tender->refresh();
        $this->assertSame('45000.00', $tender->estimated_value);
        $this->assertSame('active_bidding', $tender->status);

        // Transition to Awarded
        $statusResp = $this->actingAs($this->owner)->patchJson('/tenders/'.$tender->id.'/status', [
            'status' => 'awarded',
            'reason' => 'Official award notice received on 15 Nov.',
        ]);
        $statusResp->assertOk();

        $tender->refresh();
        $this->assertSame('awarded', $tender->status);
        $this->assertSame('Official award notice received on 15 Nov.', $tender->status_reason);
    }

    public function test_uploading_and_deleting_tender_attachments(): void
    {
        $tender = new Tender([
            'reference_number' => 'TELONE/FIBRE/2026',
            'title' => 'Fibre Optic Trenching Works',
            'procuring_entity' => 'TelOne Zimbabwe',
            'procurement_category' => 'works',
            'closing_at' => Carbon::today()->addDays(20)->toIso8601String(),
        ]);
        $tender->save();

        $addendum = UploadedFile::fake()->create('Addendum_1.pdf', 200, 'application/pdf');

        $uploadResp = $this->actingAs($this->owner)->postJson('/tenders/'.$tender->id.'/documents', [
            'file' => $addendum,
            'document_type' => 'addendum',
            'description' => 'Addendum extending clarification deadline',
        ]);
        $uploadResp->assertOk();
        $docId = $uploadResp->json('document.id');

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $doc = TenderDocument::find($docId);
        $this->assertNotNull($doc);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));

        // Delete document
        $delResp = $this->actingAs($this->owner)->deleteJson('/tenders/documents/'.$docId);
        $delResp->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $this->assertNull(TenderDocument::find($docId));
        $this->assertFalse(Storage::disk('local')->exists($doc->file_path));
    }

    public function test_multi_tenant_isolation_for_tenders(): void
    {
        // Tenant A creates tender
        $tenderA = new Tender([
            'reference_number' => 'TENANT-A-TENDER',
            'title' => 'Confidential Tender Opportunity',
            'procuring_entity' => 'Ministry of Energy',
            'procurement_category' => 'energy',
            'closing_at' => Carbon::tomorrow()->toIso8601String(),
            'status' => 'active_bidding',
        ]);
        $tenderA->save();

        $file = UploadedFile::fake()->create('classified_rfp.pdf', 100, 'application/pdf');
        $storedPath = $file->storeAs('tenders/'.$this->owner->tenant_id.'/'.$tenderA->id, 'classified_rfp.pdf', 'local');

        $docA = new TenderDocument([
            'tender_id' => $tenderA->id,
            'file_path' => $storedPath,
            'file_name' => 'classified_rfp.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'file_hash' => hash('sha256', 'secret'),
        ]);
        $docA->save();

        // Tenant B cannot view Tenant A's tender
        $this->actingAs($this->otherOwner)->getJson('/tenders/'.$tenderA->id)->assertNotFound();

        // Tenant B cannot download Tenant A's document
        $this->actingAs($this->otherOwner)->get('/tenders/documents/'.$docA->id.'/download')->assertNotFound();

        // Tenant B cannot update Tenant A's tender
        $this->actingAs($this->otherOwner)->patchJson('/tenders/'.$tenderA->id, [
            'title' => 'Tampered Title',
        ])->assertNotFound();

        // Tenant B cannot delete Tenant A's document
        $this->actingAs($this->otherOwner)->deleteJson('/tenders/documents/'.$docA->id)->assertNotFound();

        // Tenant B listing does not contain Tenant A's tender
        $listB = $this->actingAs($this->otherOwner)->getJson('/tenders/data')->json('data.tenders');
        $this->assertEmpty($listB);
    }

    public function test_permission_guards_for_tender_management(): void
    {
        // Read-only Sales Assistant can view tender register
        $this->actingAs($this->readOnlyAssistant)->getJson('/tenders/data')->assertOk();

        // Read-only cannot create tenders
        $this->actingAs($this->readOnlyAssistant)->postJson('/tenders', [
            'reference_number' => 'UNAUTHORIZED',
            'title' => 'Attempted Create',
            'procuring_entity' => 'Entity',
            'procurement_category' => 'goods',
            'closing_at' => Carbon::tomorrow()->toIso8601String(),
        ])->assertForbidden();

        // Purchasing officer with tenders.manage can create tenders
        $this->actingAs($this->officer)->postJson('/tenders', [
            'reference_number' => 'OFFICER-PO-01',
            'title' => 'Officer Tender Entry',
            'procuring_entity' => 'POTRAZ',
            'procurement_category' => 'ict',
            'closing_at' => Carbon::tomorrow()->toIso8601String(),
        ])->assertOk();
    }

    public function test_deadline_and_closure_computation(): void
    {
        // Active future tender
        $futureTender = new Tender([
            'reference_number' => 'FUTURE-01',
            'title' => 'Closing in 3 days',
            'procuring_entity' => 'UNDP Zimbabwe',
            'procurement_category' => 'consultancy',
            'closing_at' => Carbon::now()->addDays(3)->toIso8601String(),
        ]);
        $futureTender->save();

        $this->assertFalse($futureTender->isClosed());
        $this->assertGreaterThanOrEqual(2, $futureTender->daysRemaining());

        // Past tender
        $pastTender = new Tender([
            'reference_number' => 'PAST-01',
            'title' => 'Expired Tender',
            'procuring_entity' => 'NRZ',
            'procurement_category' => 'transport',
            'closing_at' => Carbon::now()->subDays(2)->toIso8601String(),
        ]);
        $pastTender->save();

        $this->assertTrue($pastTender->isClosed());
        $this->assertLessThan(0, $pastTender->daysRemaining());
    }

    public function test_tender_submission_method_accepts_aliases_and_normalizes(): void
    {
        $closingDate = Carbon::now()->addDays(14)->toIso8601String();

        // 1. Test alias 'electronic' -> normalizes to 'electronic_portal'
        $resp = $this->actingAs($this->owner)->postJson('/tenders', [
            'reference_number' => 'ALIAS-ELEC-01',
            'title' => 'Supply with electronic alias',
            'procuring_entity' => 'TelOne',
            'procurement_category' => 'goods',
            'closing_at' => $closingDate,
            'submission_method' => 'electronic',
        ]);
        $resp->assertOk();
        $this->assertSame('electronic_portal', $resp->json('tender.submission_method'));

        // 2. Test alias 'physical' -> normalizes to 'physical_delivery'
        $respPhysical = $this->actingAs($this->owner)->postJson('/tenders', [
            'reference_number' => 'ALIAS-PHYS-02',
            'title' => 'Supply with physical alias',
            'procuring_entity' => 'Econet',
            'procurement_category' => 'goods',
            'closing_at' => $closingDate,
            'submission_method' => 'physical',
        ]);
        $respPhysical->assertOk();
        $this->assertSame('physical_delivery', $respPhysical->json('tender.submission_method'));

        // 3. Test alias 'eprocurement_portal' -> normalizes to 'electronic_portal'
        $respEproc = $this->actingAs($this->owner)->postJson('/tenders', [
            'reference_number' => 'ALIAS-EPROC-03',
            'title' => 'Supply with eprocurement alias',
            'procuring_entity' => 'ZIMRA',
            'procurement_category' => 'goods',
            'closing_at' => $closingDate,
            'submission_method' => 'eprocurement_portal',
        ]);
        $respEproc->assertOk();
        $this->assertSame('electronic_portal', $respEproc->json('tender.submission_method'));

        // 4. Test updating with alias 'electronic'
        $tenderId = $respPhysical->json('tender.id');
        $respUpdate = $this->actingAs($this->owner)->putJson('/tenders/'.$tenderId, [
            'submission_method' => 'electronic',
        ]);
        $respUpdate->assertOk();
        $this->assertSame('electronic_portal', $respUpdate->json('tender.submission_method'));
    }
}


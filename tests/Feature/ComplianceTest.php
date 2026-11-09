<?php

namespace Tests\Feature;

use App\Models\ComplianceDocument;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Compliance\ComplianceService;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private User $officer;
    private User $readOnlyAuditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $onboarding = app(Onboarding::class);

        // Primary Tenant
        $this->owner = $onboarding->register([
            'name' => 'Kuda Ndlovu',
            'email' => 'kuda@tenders.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'company_name' => 'Matopo Engineering Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['ZWG', 'USD'],
            'branch_name' => 'Harare Central',
            'branch_code' => 'HRE-01',
            'warehouse_name' => 'Main Stores',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
            'invoice_prefix' => 'MAT',
        ]);

        app(TenantContext::class)->id = $this->owner->tenant_id;

        // Officer with compliance.manage
        $this->officer = new User([
            'name' => 'Tendai Chuma',
            'email' => 'tendai@tenders.test',
            'password' => 'SecurePassword123',
        ]);
        $this->officer->tenant_id = $this->owner->tenant_id;
        $this->officer->role_id = Role::where('name', 'Purchasing Officer')->sole()->id;
        $this->officer->save();

        // Auditor with only compliance.view
        $this->readOnlyAuditor = new User([
            'name' => 'Auditor Farai',
            'email' => 'farai@tenders.test',
            'password' => 'SecurePassword123',
        ]);
        $this->readOnlyAuditor->tenant_id = $this->owner->tenant_id;
        $this->readOnlyAuditor->role_id = Role::where('name', 'Auditor')->sole()->id;
        $this->readOnlyAuditor->save();

        // Other Tenant for multi-tenancy verification
        $this->otherOwner = $onboarding->register([
            'name' => 'Other Tenant Owner',
            'email' => 'other@competitor.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'company_name' => 'Zambezi Supplies Ltd',
            'industry' => 'general',
            'base_currency' => 'USD',
            'accepted_currencies' => ['USD'],
            'branch_name' => 'Bulawayo',
            'branch_code' => 'BYO-01',
            'warehouse_name' => 'Byo Stores',
            'financial_year_start' => '01-01',
            'tax_label' => 'VAT',
            'tax_rate' => '15',
            'invoice_prefix' => 'ZAM',
        ]);

        \Illuminate\Support\Facades\DB::table('tenant_subscriptions')
            ->whereIn('tenant_id', [$this->owner->tenant_id, $this->otherOwner->tenant_id])
            ->update(['status' => 'active']);

        app(TenantContext::class)->id = $this->owner->tenant_id;
    }

    public function test_guest_is_redirected_from_compliance_endpoints(): void
    {
        $this->getJson('/compliance/data')->assertUnauthorized();
        $this->postJson('/compliance/store', [])->assertUnauthorized();
    }

    public function test_compliance_data_lists_standards_and_scorecard(): void
    {
        $resp = $this->actingAs($this->owner)->getJson('/compliance/data');

        $resp->assertOk();
        $data = $resp->json('data');

        $this->assertNotEmpty($data['documents']);
        $this->assertNotEmpty($data['categories']);
        $this->assertArrayHasKey('summary', $data);

        // Initial state: mandatory items are missing
        $this->assertGreaterThan(0, $data['summary']['missing_count']);
        $this->assertSame(0, $data['summary']['valid_count']);

        // Check ITF263 and PRAZ are in the catalog
        $typeKeys = array_column($data['documents'], 'type_key');
        $this->assertContains('praz_certificate', $typeKeys);
        $this->assertContains('zimra_itf263', $typeKeys);
        $this->assertContains('nssa_clearance', $typeKeys);
    }

    public function test_uploading_statutory_compliance_document(): void
    {
        $file = UploadedFile::fake()->create('zimra_itf263_2026.pdf', 250, 'application/pdf');

        $futureDate = Carbon::today()->addMonths(6)->format('Y-m-d');
        $pastDate = Carbon::today()->subMonths(6)->format('Y-m-d');

        $resp = $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'statutory',
            'type_key' => 'zimra_itf263',
            'title' => 'ZIMRA ITF263 Tax Clearance Certificate',
            'issuing_authority' => 'ZIMRA',
            'reference_number' => 'ITF263-2026-998822',
            'issue_date' => $pastDate,
            'expiry_date' => $futureDate,
            'file' => $file,
            'notes' => 'Current valid tax clearance certificate',
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('document.verification_status', 'valid');
        $resp->assertJsonPath('document.version', 1);

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $doc = ComplianceDocument::active()->where('type_key', 'zimra_itf263')->first();
        $this->assertNotNull($doc);
        $this->assertSame('valid', $doc->calculateComputedStatus());
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
    }

    public function test_expiry_status_computation(): void
    {
        $file = UploadedFile::fake()->create('praz_cert.pdf', 150, 'application/pdf');

        // Expiring in 10 days
        $expiringSoon = Carbon::today()->addDays(10)->format('Y-m-d');

        $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'statutory',
            'type_key' => 'praz_certificate',
            'title' => 'PRAZ Supplier Registration Certificate',
            'issuing_authority' => 'PRAZ',
            'reference_number' => 'PRAZ-2026-CAT-01',
            'praz_categories' => ['GS001', 'SE002'],
            'expiry_date' => $expiringSoon,
            'file' => $file,
        ])->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $doc = ComplianceDocument::active()->where('type_key', 'praz_certificate')->first();
        $this->assertSame('expiring', $doc->calculateComputedStatus());

        // Now test expired
        $expiredDate = Carbon::today()->subDays(5)->format('Y-m-d');
        $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'statutory',
            'type_key' => 'nssa_clearance',
            'title' => 'NSSA Compliance Clearance Certificate',
            'expiry_date' => $expiredDate,
            'file' => $file,
        ])->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $nssaDoc = ComplianceDocument::active()->where('type_key', 'nssa_clearance')->first();
        $this->assertSame('expired', $nssaDoc->calculateComputedStatus());
    }

    public function test_document_replacement_and_version_history(): void
    {
        $file1 = UploadedFile::fake()->create('cr14_v1.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('cr14_v2.pdf', 120, 'application/pdf');

        // Upload Version 1
        $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'company_registration',
            'type_key' => 'cr14_directors',
            'title' => 'CR14 List of Directors',
            'reference_number' => 'CR14-2024',
            'file' => $file1,
        ])->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $v1 = ComplianceDocument::active()->where('type_key', 'cr14_directors')->first();
        $this->assertSame(1, $v1->version);
        $this->assertTrue($v1->is_active);

        // Upload Version 2 (replacement)
        $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'company_registration',
            'type_key' => 'cr14_directors',
            'title' => 'CR14 List of Directors (Updated Board)',
            'reference_number' => 'CR14-2026',
            'file' => $file2,
        ])->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        // Refresh database state
        $v1->refresh();
        $this->assertFalse($v1->is_active); // Archived

        $v2 = ComplianceDocument::active()->where('type_key', 'cr14_directors')->first();
        $this->assertSame(2, $v2->version);
        $this->assertTrue($v2->is_active);
        $this->assertSame($v1->id, $v2->parent_document_id);

        // Check history API endpoint
        $historyResp = $this->actingAs($this->owner)->getJson('/compliance/history/'.$v2->id);
        $historyResp->assertOk();
        $history = $historyResp->json('data');
        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]['version']);
        $this->assertSame(1, $history[1]['version']);
    }

    public function test_marking_conditional_requirement_as_not_applicable(): void
    {
        // Marking VAT as N/A (valid because VAT is conditional on turnover)
        $resp = $this->actingAs($this->owner)->postJson('/compliance/mark-na', [
            'type_key' => 'zimra_vat',
            'reason' => 'Annual turnover currently below ZIMRA statutory VAT threshold',
        ]);
        $resp->assertOk();

        app(TenantContext::class)->id = $this->owner->tenant_id;
        $doc = ComplianceDocument::active()->where('type_key', 'zimra_vat')->first();
        $this->assertNotNull($doc);
        $this->assertSame('not_applicable', $doc->verification_status);

        // Marking a mandatory statutory document (ITF263) as N/A should be rejected
        $failResp = $this->actingAs($this->owner)->postJson('/compliance/mark-na', [
            'type_key' => 'zimra_itf263',
            'reason' => 'We do not want to provide tax clearance',
        ]);
        $failResp->assertStatus(422);
    }

    public function test_tenant_isolation_prevents_cross_tenant_access_and_downloads(): void
    {
        // Tenant A uploads a document
        $file = UploadedFile::fake()->create('confidential_director_cr14.pdf', 300, 'application/pdf');
        $resp = $this->actingAs($this->owner)->postJson('/compliance/store', [
            'category' => 'company_registration',
            'type_key' => 'cr14_directors',
            'title' => 'Tenant A CR14 Directors',
            'file' => $file,
        ]);
        $docId = $resp->json('document.id');

        // Tenant B attempts to view/download Tenant A's document
        $this->actingAs($this->otherOwner)->getJson('/compliance/download/'.$docId)->assertNotFound();
        $this->actingAs($this->otherOwner)->getJson('/compliance/history/'.$docId)->assertNotFound();

        // Tenant B's compliance data does NOT include Tenant A's uploaded file
        $otherData = $this->actingAs($this->otherOwner)->getJson('/compliance/data')->json('data');
        $otherDoc = collect($otherData['documents'])->firstWhere('type_key', 'cr14_directors');
        $this->assertFalse($otherDoc['is_persisted']);
        $this->assertSame('missing', $otherDoc['verification_status']);
    }

    public function test_permission_guards_for_read_only_and_managers(): void
    {
        // Read-only auditor can view compliance data
        $this->actingAs($this->readOnlyAuditor)->getJson('/compliance/data')->assertOk();

        // Read-only auditor cannot store documents or mark N/A
        $file = UploadedFile::fake()->create('attempt.pdf', 100, 'application/pdf');
        $this->actingAs($this->readOnlyAuditor)->postJson('/compliance/store', [
            'category' => 'statutory',
            'type_key' => 'zimra_itf263',
            'title' => 'Attempted Upload',
            'file' => $file,
        ])->assertForbidden();

        $this->actingAs($this->readOnlyAuditor)->postJson('/compliance/mark-na', [
            'type_key' => 'zimra_vat',
            'reason' => 'Attempted NA',
        ])->assertForbidden();

        // Purchasing officer with compliance.manage can store
        $this->actingAs($this->officer)->postJson('/compliance/store', [
            'category' => 'statutory',
            'type_key' => 'zimra_itf263',
            'title' => 'Officer Uploaded ITF263',
            'file' => $file,
        ])->assertOk();
    }
}

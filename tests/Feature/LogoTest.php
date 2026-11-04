<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLogoAsset;
use App\Models\TenantModule;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_is_private_and_rejects_non_images(): void
    {
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        DB::table('tenant_subscriptions')->where('tenant_id', $owner->tenant_id)->update(['status' => 'active']);
        $this->actingAs($owner)->post('/company/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $owner->tenant_id;
        Storage::disk('local')->assertExists(Company::first()->logo_path);
        $html = view('partials.company-logo')->render();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Shop logo', $html);
        TenantModule::where('key', 'administration')->update(['enabled' => false]);
        $this->get('/company/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        app(TenantContext::class)->id = $owner->tenant_id;
        TenantModule::where('key', 'administration')->update(['enabled' => true]);
        $this->post('/company/logo', ['logo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');
        app(TenantContext::class)->id = $owner->tenant_id;
        $company = Company::firstOrFail();
        Storage::disk('local')->delete($company->logo_path);
        $this->assertNotNull($company->logo_url);
        $this->assertStringContainsString('data:image/png;base64,', view('partials.company-logo')->render());
        $this->get('/company/logo')->assertOk();
        app(TenantContext::class)->id = $owner->tenant_id;
        $company->logoAsset()->delete();
        $this->assertNull($company->logo_url);
        $this->assertStringNotContainsString('<img', view('partials.company-logo')->render());
        $this->get('/company/logo')->assertNotFound();
        auth()->logout();
        $this->get('/company/logo')->assertRedirect('/login');
    }

    public function test_supported_formats_are_served_as_png(): void
    {
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'formats@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        DB::table('tenant_subscriptions')->where('tenant_id', $owner->tenant_id)->update(['status' => 'active']);
        $this->actingAs($owner);

        foreach (['png', 'jpeg', 'webp', 'gif', 'bmp'] as $format) {
            $image = imagecreatetruecolor(120, 40);
            imagefill($image, 0, 0, imagecolorallocate($image, 20, 100, 70));
            ob_start();
            $encoder = 'image'.$format;
            $encoder($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $this->post('/company/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.'.$format, $bytes)])
                ->assertSessionHasNoErrors();
            $response = $this->get('/company/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
            $info = getimagesizefromstring($response->getContent());
            $this->assertSame([120, 40], array_slice($info, 0, 2));
        }
    }

    public function test_logo_survives_refresh_navigation_login_and_missing_upload_files(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'persistent-logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Persistent Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        DB::table('tenant_subscriptions')->where('tenant_id', $owner->tenant_id)->update(['status' => 'active']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'purchasing', 'sales', 'backoffice', 'pos', 'hr', 'payroll', 'currencies', 'loyalty', 'reports'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($owner, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $this->actingAs($owner)->post('/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $owner->tenant_id;
        $company = Company::firstOrFail();
        $url = $company->logo_url;
        $this->assertNotNull($url);
        $bytes = $this->get('/company/logo')->assertOk()->getContent();
        Storage::disk('local')->delete($company->logo_path);
        $this->assertSame($bytes, $this->get('/company/logo')->assertOk()->getContent());
        foreach (['dashboard', 'company', 'inventory', 'sales', 'purchasing', 'backoffice', 'accounting', 'pos', 'hr', 'payroll', 'loyalty', 'reports'] as $page) {
            for ($refresh = 0; $refresh < 2; $refresh++) {
                $this->get('/'.$page)->assertOk()->assertInertia(fn ($inertia) => $inertia->component('Workspace')->where('company.logo_url', $url));
            }
        }
        $this->post('/logout')->assertRedirect();
        $this->get('/company/logo')->assertRedirect('/login');
        $this->actingAs($owner)->get('/company/logo')->assertOk()->assertContent($bytes);
        $this->post('/company/logo', ['logo' => UploadedFile::fake()->image('replacement.png', 60, 60)])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $owner->tenant_id;
        $this->assertNotSame($url, Company::firstOrFail()->logo_url);
        $this->assertSame(1, CompanyLogoAsset::count());
    }

    public function test_logo_assets_remain_private_to_their_company(): void
    {
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'first-logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'First Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        DB::table('tenant_subscriptions')->where('tenant_id', $owner->tenant_id)->update(['status' => 'active']);
        $this->actingAs($owner)->post('/company/logo', ['logo' => UploadedFile::fake()->image('first.png')])->assertSessionHasNoErrors();
        $other = app(Onboarding::class)->register(['name' => 'Other', 'email' => 'second-logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Other Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        DB::table('tenant_subscriptions')->where('tenant_id', $other->tenant_id)->update(['status' => 'active']);
        $this->actingAs($other)->get('/company/logo')->assertNotFound();
        $this->actingAs($owner)->get('/company/logo')->assertOk();
    }

    public function test_upgrade_preserves_existing_logo_files_in_the_database(): void
    {
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'legacy-logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Legacy Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        $file = UploadedFile::fake()->image('legacy.png', 30, 20);
        $path = $file->store('company-logos/'.$owner->tenant_id, 'local');
        Company::firstOrFail()->update(['logo_path' => $path]);
        $migration = require database_path('migrations/2026_11_04_180000_persist_company_logos.php');
        $migration->down();
        $migration->up();
        Storage::disk('local')->delete($path);
        $company = Company::firstOrFail();
        $this->assertNotNull($company->logo_url);
        $this->assertStringStartsWith('data:image/png;base64,', $company->document_logo);
    }
}

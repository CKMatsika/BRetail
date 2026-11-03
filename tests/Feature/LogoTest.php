<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TenantModule;
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
        $this->assertStringNotContainsString('<img', view('partials.company-logo')->render());
        auth()->logout();
        $this->get('/company/logo')->assertRedirect('/login');
    }
}

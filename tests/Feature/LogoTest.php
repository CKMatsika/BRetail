<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_is_private_and_rejects_non_images(): void
    {
        Storage::fake('local');
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'logo@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        $this->actingAs($owner)->post('/company/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $owner->tenant_id;
        Storage::disk('local')->assertExists(Company::first()->logo_path);
        $this->get('/company/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/company/logo', ['logo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');
        auth()->logout();
        $this->get('/company/logo')->assertRedirect('/login');
    }
}

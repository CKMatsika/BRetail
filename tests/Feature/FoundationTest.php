<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function data(string $email = 'owner@example.test'): array
    {
        return ['name' => 'Alex Moyo', 'email' => $email, 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123', 'company_name' => 'Little Steps Retail', 'industry' => 'apparel', 'base_currency' => 'USD', 'accepted_currencies' => ['ZWG', 'ZAR'], 'branch_name' => 'Harare', 'branch_code' => 'HRE', 'warehouse_name' => 'Harare warehouse', 'financial_year_start' => '01-01', 'tax_label' => 'Tax', 'tax_rate' => '0', 'invoice_prefix' => 'INV'];
    }

    private function owner(string $email = 'owner@example.test'): User
    {
        return app(Onboarding::class)->register($this->data($email));
    }

    private function member(User $owner, string $role = 'Cashier'): User
    {
        app(TenantContext::class)->id = $owner->tenant_id;
        $u = new User(['name' => 'Team member', 'email' => uniqid().'@example.test', 'password' => 'SecurePassword123']);
        $u->tenant_id = $owner->tenant_id;
        $u->role_id = Role::where('name', $role)->sole()->id;
        $u->save();
        $u->branches()->attach(Branch::first()->id, ['tenant_id' => $owner->tenant_id]);

        return $u;
    }

    public function test_guest_is_redirected_and_api_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }

    public function test_registration_creates_complete_company_atomically(): void
    {
        $this->post('/register', $this->data())->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('roles', 12);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('warehouses', 1);
        $this->assertDatabaseCount('tenant_modules', 16);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertTrue(Hash::check('SecurePassword123', User::first()->password));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Workspace')->where('company.name', 'Little Steps Retail'));
    }

    public function test_invalid_onboarding_writes_nothing(): void
    {
        $data = $this->data();
        $data['base_currency'] = 'invalid';
        $this->post('/register', $data)->assertSessionHasErrors('base_currency');
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_onboarding_rolls_back_on_exception(): void
    {
        $this->owner();
        $data = $this->data();
        try {
            app(Onboarding::class)->register($data);
            $this->fail('Expected duplicate owner email to fail');
        } catch (QueryException $e) {
            $this->assertDatabaseCount('tenants', 1);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('roles', 12);
            $this->assertDatabaseCount('audit_events', 1);
        }
    }

    public function test_login_logout_and_inactive_user(): void
    {
        $u = $this->owner();
        app(TenantContext::class)->id = null;
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => 'SecurePassword123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $u->active = false;
        $u->save();
        $this->post('/login', ['email' => $u->email, 'password' => 'SecurePassword123'])->assertSessionHasErrors('email');
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'missing@test.test', 'password' => 'invalid']);
        }$this->post('/login', ['email' => 'missing@test.test', 'password' => 'invalid'])->assertStatus(429);
    }

    public function test_tenant_cannot_read_or_change_another_tenant_branch(): void
    {
        $a = $this->owner();
        $aid = Branch::first()->id;
        $b = $this->owner('second@example.test');
        $bid = Branch::first()->id;
        $this->actingAs($a)->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $aid);
        $this->getJson('/api/v1/branches/'.$bid)->assertNotFound();
        $this->patch('/branches/'.$bid, ['name' => 'Intrusion', 'active' => true])->assertNotFound();
    }

    public function test_tenant_scope_fails_closed(): void
    {
        $this->owner();
        app(TenantContext::class)->id = null;
        $this->expectException(\LogicException::class);
        Branch::count();
    }

    public function test_cashier_cannot_access_administration_or_create_branch(): void
    {
        $o = $this->owner();
        $cashier = $this->member($o);
        $this->actingAs($cashier)->get('/dashboard')->assertOk();
        foreach (['/team', '/company', '/modules', '/audit', '/branches'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/branches', ['name' => 'Unauthorized'])->assertForbidden();
        $this->getJson('/api/v1/branches')->assertForbidden();
    }

    public function test_branch_manager_only_sees_assigned_branch(): void
    {
        $o = $this->owner();
        $m = $this->member($o, 'Branch Manager');
        $other = Branch::create(['name' => 'Bulawayo', 'code' => 'BYO']);
        $this->actingAs($m)->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/branches/'.$other->id)->assertForbidden();
        $this->patch('/branches/'.$other->id, ['name' => 'Changed', 'active' => true])->assertForbidden();
    }

    public function test_branch_creation_is_tenant_unique_and_audited(): void
    {
        $o = $this->owner();
        $this->actingAs($o)->post('/branches', ['name' => 'Bulawayo', 'code' => 'BYO', 'warehouse_name' => 'Store room'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branches', ['code' => 'BYO', 'tenant_id' => $o->tenant_id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'branch.created']);
        $this->post('/branches', ['name' => 'Duplicate', 'code' => 'BYO', 'warehouse_name' => 'Store room'])->assertSessionHasErrors('code');
    }

    public function test_disabled_module_is_blocked_on_web_and_api(): void
    {
        $o = $this->owner();
        TenantModule::where('key', 'branches')->update(['enabled' => false]);
        $this->actingAs($o)->get('/branches')->assertForbidden();
        $this->getJson('/api/v1/branches')->assertForbidden();
    }

    public function test_required_module_cannot_be_disabled_and_future_module_cannot_activate(): void
    {
        $o = $this->owner();
        $this->actingAs($o)->patch('/modules/branches', ['enabled' => false, 'preferred' => false])->assertSessionHasErrors('module');
        $this->patch('/modules/accounting', ['enabled' => true, 'preferred' => true])->assertSessionHasErrors('module');
        $this->assertDatabaseHas('tenant_modules', ['tenant_id' => $o->tenant_id, 'key' => 'accounting', 'enabled' => false]);
    }

    public function test_future_module_preferences_are_saved_and_audited(): void
    {
        $o = $this->owner();
        $this->actingAs($o)->patch('/modules/inventory', ['enabled' => false, 'preferred' => true])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $o->tenant_id;
        $this->assertTrue(TenantModule::where('key', 'inventory')->first()->settings['preferred']);
        $this->assertDatabaseHas('audit_events', ['action' => 'module.configured']);
    }

    public function test_user_creation_rejects_foreign_role_and_foreign_branch(): void
    {
        $a = $this->owner();
        $aRole = Role::where('name', 'Cashier')->first()->id;
        $b = $this->owner('second@example.test');
        $foreignRole = Role::first()->id;
        $foreignBranch = Branch::first()->id;
        $data = ['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'SecurePassword123', 'role_id' => $foreignRole, 'branch_ids' => [$foreignBranch]];
        $this->actingAs($a)->post('/team', $data)->assertSessionHasErrors(['role_id', 'branch_ids.0']);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.test']);
    }

    public function test_team_member_creation_and_deactivation_revoke_access(): void
    {
        $o = $this->owner();
        $role = Role::where('name', 'Cashier')->first()->id;
        $branch = Branch::first()->id;
        $this->actingAs($o)->post('/team', ['name' => 'Cashier', 'email' => 'cashier@example.test', 'password' => 'SecurePassword123', 'role_id' => $role, 'branch_ids' => [$branch]])->assertSessionHasNoErrors();
        $cashier = User::where('email', 'cashier@example.test')->firstOrFail();
        $token = $cashier->createToken('test')->plainTextToken;
        $this->patch('/team/'.$cashier->id, ['active' => false, 'role_id' => $role, 'branch_ids' => [$branch]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->actingAs($cashier->fresh())->get('/dashboard')->assertForbidden();
    }

    public function test_owner_cannot_disable_self(): void
    {
        $o = $this->owner();
        $this->actingAs($o)->patch('/team/'.$o->id, ['active' => false])->assertStatus(422);
    }

    public function test_company_settings_merge_preserves_template(): void
    {
        $o = $this->owner();
        $this->actingAs($o)->patch('/company', ['name' => 'New name', 'settings' => ['invoice_prefix' => 'NEW', 'phone' => '+263123456']])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $o->tenant_id;
        $c = Company::first();
        $this->assertSame('New name', $c->name);
        $this->assertContains('Size', $c->settings['attributes']);
    }

    public function test_sanctum_token_inherits_tenant_and_permissions(): void
    {
        $o = $this->owner();
        $token = $o->createToken('test')->plainTextToken;
        $this->getJson('/api/v1/branches', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_audit_records_cannot_be_edited(): void
    {
        $o = $this->owner();
        $event = AuditEvent::first();
        $this->expectException(\LogicException::class);
        $event->update(['action' => 'rewritten']);
    }

    public function test_composite_foreign_key_rejects_foreign_warehouse_branch(): void
    {
        $a = $this->owner();
        $b = $this->owner('second@example.test');
        $foreignBranch = Branch::first()->id;
        $this->expectException(QueryException::class);
        DB::table('warehouses')->insert(['tenant_id' => $a->tenant_id, 'branch_id' => $foreignBranch, 'name' => 'Invalid']);
    }
}

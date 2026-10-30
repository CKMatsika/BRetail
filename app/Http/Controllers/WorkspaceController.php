<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DeviceEnrollment;
use App\Models\PosHold;
use App\Models\PosSession;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\ModuleRegistry;
use App\Services\TradeLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkspaceController
{
    private function permit(Request $r, string $permission): void
    {
        abort_unless($r->user()->canDo($permission), 403);
    }

    private function branches(Request $r)
    {
        $query = Branch::query();
        if (! $r->user()->role->company_wide) {
            $query->whereIn('id', $r->user()->branches()->pluck('branches.id'));
        }

        return $query;
    }

    public function page(Request $r, string $section = 'dashboard')
    {
        $permission = match ($section) {
            'team' => 'users.view','devices' => 'devices.manage','company' => 'company.view','modules' => 'modules.view','audit' => 'audit.view',default => "$section.view"
        };
        $this->permit($r, $permission);
        $company = Company::firstOrFail();
        $companyData = $r->user()->canDo('company.view') ? $company->toArray() : [...$company->only('name', 'industry', 'base_currency', 'accepted_currencies'), 'settings' => []];
        $data = ['section' => $section, 'user' => $r->user()->only('id', 'name', 'email'), 'role' => $r->user()->role->name, 'permissions' => $r->user()->role->permissions, 'company' => $companyData, 'branches' => $this->branches($r)->with('warehouses')->get(), 'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config("modules.$m->key")]), 'templates' => config('retail.templates')];
        if ($section === 'team') {
            $data['users'] = User::where('tenant_id', $r->user()->tenant_id)->with('role', 'branches')->get()->map(fn ($u) => [...$u->only('id', 'name', 'email', 'active'), 'role' => $u->role?->name, 'role_id' => $u->role_id, 'branch_ids' => $u->branches->pluck('id')]);
            $data['roles'] = Role::get(['id', 'name', 'permissions', 'company_wide']);
        }
        if ($section === 'devices') {
            $data['devices'] = DeviceEnrollment::with(['user:id,name', 'branch:id,name'])
                ->whereIn('branch_id', $this->branches($r)->select('id'))
                ->latest('id')->get()
                ->map(fn ($device) => [
                    'id' => $device->id, 'device_uuid' => $device->device_uuid, 'name' => $device->name,
                    'platform' => $device->platform, 'user' => $device->user?->name,
                    'branch' => $device->branch?->name, 'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    'revoked_at' => $device->revoked_at?->toIso8601String(),
                ]);
        }
        if ($section === 'audit') {
            $data['events'] = AuditEvent::latest('id')->limit(100)->get();
        }

        return Inertia::render('Workspace', $data);
    }

    public function storeBranch(Request $r)
    {
        Gate::authorize('create', Branch::class);
        $data = $r->validate(['name' => 'required|string|max:120', 'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('branches')->where('tenant_id', $r->user()->tenant_id)], 'address' => 'nullable|string|max:255', 'warehouse_name' => 'required|string|max:120']);
        DB::transaction(function () use ($data) {
            $branch = Branch::create(collect($data)->except('warehouse_name')->all());
            Warehouse::create(['branch_id' => $branch->id, 'name' => $data['warehouse_name']]);
            Audit::record('branch.created', (string) $branch->id);
        });

        return back()->with('success', 'Branch and warehouse created.');
    }

    public function updateBranch(Request $r, string $id)
    {
        $branch = Branch::findOrFail($id);
        Gate::authorize('update', $branch);
        $data = $r->validate(['name' => 'required|string|max:120', 'address' => 'nullable|string|max:255', 'active' => 'required|boolean']);
        DB::transaction(function () use ($branch, $data) {
            Tenant::whereKey($branch->tenant_id)->lockForUpdate()->firstOrFail();
            if (! $data['active']) {
                app(TradeLedger::class)->assertBranchClosable($branch->id);
                $warehouseIds = Warehouse::where('branch_id', $branch->id)->pluck('id');
                if (PosSession::whereIn('warehouse_id', $warehouseIds)->where('status', 'open')->exists() || PosHold::whereIn('warehouse_id', $warehouseIds)->where('status', 'held')->exists() || StockCount::whereIn('warehouse_id', $warehouseIds)->where('status', 'draft')->exists()) {
                    throw ValidationException::withMessages(['active' => 'Close shifts, resolve held carts and finish or cancel stock counts before deactivating this branch.']);
                }
            }
            $branch->update($data);
            Audit::record('branch.updated', (string) $branch->id, $data);
        });

        return back()->with('success', 'Branch updated.');
    }

    public function storeUser(Request $r)
    {
        $this->permit($r, 'users.configure');
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', Password::min(12)->letters()->numbers()], 'role_id' => ['required', Rule::exists('roles', 'id')->where('tenant_id', $r->user()->tenant_id)], 'branch_ids' => 'required|array|min:1', 'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('tenant_id', $r->user()->tenant_id)]]);
        DB::transaction(function () use ($data, $r) {
            $user = new User(collect($data)->only('name', 'email', 'password')->all());
            $user->tenant_id = $r->user()->tenant_id;
            $user->role_id = $data['role_id'];
            $user->save();
            foreach ($data['branch_ids'] as $id) {
                $user->branches()->attach($id, ['tenant_id' => $user->tenant_id]);
            }Audit::record('user.created', (string) $user->id, ['role_id' => $user->role_id, 'branch_ids' => $data['branch_ids']]);
        });

        return back()->with('success', 'Team member created.');
    }

    public function updateUser(Request $r, string $id)
    {
        $this->permit($r, 'users.configure');
        $user = User::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
        abort_if($user->id === $r->user()->id, 422, 'You cannot change your own access.');
        $data = $r->validate(['active' => 'required|boolean', 'role_id' => ['required', Rule::exists('roles', 'id')->where('tenant_id', $r->user()->tenant_id)], 'branch_ids' => 'required|array|min:1', 'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('tenant_id', $r->user()->tenant_id)]]);
        DB::transaction(function () use ($data, $user) {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $user->active = $data['active'];
            $user->role_id = $data['role_id'];
            $user->save();
            $user->branches()->sync(collect($data['branch_ids'])->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
            if (! $user->active) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }Audit::record('user.access_updated', (string) $user->id, $data);
        });

        return back()->with('success', 'Access updated.');
    }

    public function module(Request $r, string $key, ModuleRegistry $registry)
    {
        $this->permit($r, 'modules.configure');
        $data = $r->validate(['enabled' => 'required|boolean', 'preferred' => 'required|boolean']);
        $registry->configure($key, $data['enabled'], ['preferred' => $data['preferred']]);

        return back()->with('success', 'Module preference saved.');
    }

    public function company(Request $r)
    {
        $this->permit($r, 'company.configure');
        $data = $r->validate(['name' => 'required|string|max:160', 'settings.registration' => 'nullable|string|max:120', 'settings.tax_id' => 'nullable|string|max:120', 'settings.phone' => 'nullable|string|max:40', 'settings.contact_email' => 'nullable|email|max:255', 'settings.address' => 'nullable|string|max:255', 'settings.receipt_footer' => 'nullable|string|max:255', 'settings.invoice_prefix' => 'required|alpha_dash|max:20']);
        DB::transaction(function () use ($data) {
            $company = Company::firstOrFail();
            $company->update(['name' => $data['name'], 'settings' => array_merge($company->settings, $data['settings'])]);
            Audit::record('company.updated', (string) $company->id);
        });

        return back()->with('success', 'Company details saved.');
    }

    public function apiBranches(Request $r)
    {
        $this->permit($r, 'branches.view');

        return response()->json(['data' => $this->branches($r)->with('warehouses')->get()]);
    }

    public function apiBranch(Request $r, string $id)
    {
        $branch = Branch::findOrFail($id);
        Gate::authorize('view', $branch);

        return response()->json(['data' => $branch->load('warehouses')]);
    }
}

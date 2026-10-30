<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class Onboarding
{
    public function register(array $data): User
    {
        if (! config('retail.templates.'.$data['industry'])) {
            throw new \InvalidArgumentException('Unknown business template.');
        }

        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create(['name' => $data['company_name']]);
            app(TenantContext::class)->id = $tenant->id;
            foreach (config('retail.roles') as $name => $permissions) {
                Role::create(['name' => $name, 'permissions' => array_values(array_unique([...$permissions, ...config('inventory.role_permissions.'.$name, []), ...config('pos.role_permissions.'.$name, []), ...config('accounting.role_permissions.'.$name, []), ...config('trade.role_permissions.'.$name, []), ...config('international.role_permissions.'.$name, []), ...config('hr.role_permissions.'.$name, []), ...config('payroll.role_permissions.'.$name, []), ...config('reports.role_permissions.'.$name, [])])), 'company_wide' => in_array($name, config('retail.company_wide_roles'), true)]);
            }
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->tenant_id = $tenant->id;
            $user->role_id = Role::where('name', 'Business Owner')->sole()->id;
            $user->save();
            Company::create(['name' => $data['company_name'], 'industry' => $data['industry'], 'base_currency' => $data['base_currency'], 'accepted_currencies' => array_values(array_unique([$data['base_currency'], ...($data['accepted_currencies'] ?? [])])), 'settings' => [
                'registration' => $data['registration'] ?? '', 'tax_id' => $data['tax_id'] ?? '', 'contact_email' => $data['contact_email'] ?? '', 'phone' => $data['phone'] ?? '', 'address' => $data['address'] ?? '',
                'financial_year_start' => $data['financial_year_start'] ?? '01-01', 'tax_label' => $data['tax_label'] ?? 'Tax', 'tax_rate' => $data['tax_rate'] ?? '0', 'receipt_footer' => $data['receipt_footer'] ?? 'Thank you for shopping with us.', 'invoice_prefix' => $data['invoice_prefix'] ?? 'INV', 'attributes' => config('retail.templates.'.$data['industry'].'.attributes'),
            ]]);
            $branch = Branch::create(['name' => $data['branch_name'], 'code' => $data['branch_code'], 'address' => $data['address'] ?? null]);
            Warehouse::create(['branch_id' => $branch->id, 'name' => $data['warehouse_name']]);
            $user->branches()->attach($branch->id, ['tenant_id' => $tenant->id]);
            foreach (config('modules') as $key => $module) {
                TenantModule::create(['key' => $key, 'enabled' => $module['phase'] === 1, 'settings' => ['preferred' => in_array($key, $data['preferred_modules'] ?? [], true)]]);
            }
            Audit::record('company.onboarded', (string) $tenant->id, ['owner_id' => $user->id]);

            return $user;
        });
    }
}

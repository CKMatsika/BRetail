<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $extra = config('loyalty.role_permissions.'.$role->name, []);
            if ($extra) {
                $permissions = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...$permissions, ...$extra])), JSON_THROW_ON_ERROR),
                ]);
            }
        }

        foreach (DB::table('accounting_settings')->get(['tenant_id']) as $setting) {
            foreach ([['LOY-LIAB', 'Loyalty points liability', 'liability', 'loyalty_liability'], ['LOY-EXP', 'Loyalty rewards expense', 'expense', 'loyalty_expense']] as [$code, $name, $type, $role]) {
                $account = DB::table('chart_accounts')->where('tenant_id', $setting->tenant_id)->where('code', $code)->first();
                if (! $account) {
                    $id = DB::table('chart_accounts')->insertGetId([
                        'tenant_id' => $setting->tenant_id, 'code' => $code, 'name' => $name, 'type' => $type,
                        'report_group' => $type, 'is_cash' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $id = $account->id;
                }
                DB::table('account_mappings')->updateOrInsert(
                    ['tenant_id' => $setting->tenant_id, 'role' => $role],
                    ['account_id' => $id],
                );
            }
        }
    }

    public function down(): void
    {
        // Keep role grants and control accounts to preserve any posted loyalty journals.
    }
};

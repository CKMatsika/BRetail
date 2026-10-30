<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;

final class AccountingAccess
{
    public static function permit(User $u, string $permission): void
    {
        InventoryAccess::permit($u, $permission);
    }

    public static function lock(User $u, ?string $permission = null): void
    {
        Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
        $u->refresh();
        $u->unsetRelation('role');
        abort_unless($u->active && $u->tenant_id === app(TenantContext::class)->requireId(), 403);
        if ($permission) {
            self::permit($u, $permission);
        }abort_unless(TenantModule::where('key', 'accounting')->where('enabled', true)->exists(), 403, 'Accounting is disabled.');
    }

    public static function branches(User $u)
    {
        $q = Branch::query();
        if (! $u->role->company_wide) {
            $q->whereIn('id', $u->branches()->select('branches.id'));
        }

return $q;
    }

    public static function branch(User $u, int $id, bool $active = false): Branch
    {
        $b = self::branches($u)->findOrFail($id);
        if ($active) {
            abort_unless($b->active, 422, 'Branch is inactive.');
        }

return $b;
    }

    public static function setting(): AccountingSetting
    {
        return AccountingSetting::first() ?? abort(423, 'Initialize the accounting chart and period first.');
    }

    public static function book(): string
    {
        return self::setting()->live_enabled ? 'live' : 'training';
    }
}

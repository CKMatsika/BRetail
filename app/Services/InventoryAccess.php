<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;

final class InventoryAccess
{
    public static function permit(User $user, string $permission): void
    {
        abort_unless($user->tenant_id === app(TenantContext::class)->requireId() && $user->canDo($permission), 403);
    }

    public static function warehouses(User $user)
    {
        $q = Warehouse::query()->whereIn('branch_id', Branch::where('active', true)->select('id'));
        if (! $user->role->company_wide) {
            $q->whereIn('branch_id', $user->branches()->select('branches.id'));
        }

        return $q;
    }

    public static function warehouse(User $user, int $id): Warehouse
    {
        return self::warehouses($user)->findOrFail($id);
    }
}

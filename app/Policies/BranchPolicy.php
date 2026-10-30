<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function view(User $u, Branch $b): bool
    {
        return $b->tenant_id === $u->tenant_id && $u->canDo('branches.view') && ($u->role->company_wide || $u->branches()->where('branches.id', $b->id)->exists());
    }

    public function update(User $u, Branch $b): bool
    {
        return $u->canDo('branches.edit') && $this->view($u, $b);
    }

    public function create(User $u): bool
    {
        return $u->canDo('branches.create') && $u->role->company_wide;
    }
}

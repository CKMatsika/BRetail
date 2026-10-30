<?php

namespace App\Models;

class BranchTransfer extends TenantRecord
{
    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}

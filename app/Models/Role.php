<?php

namespace App\Models;

class Role extends TenantRecord
{
    protected function casts(): array
    {
        return ['permissions' => 'array', 'company_wide' => 'boolean'];
    }
}

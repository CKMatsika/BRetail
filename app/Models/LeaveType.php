<?php

namespace App\Models;

class LeaveType extends TenantRecord
{
    protected function casts(): array
    {
        return ['annual_entitlement' => 'decimal:2', 'paid' => 'boolean', 'active' => 'boolean'];
    }
}

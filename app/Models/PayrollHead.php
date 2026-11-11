<?php

namespace App\Models;

class PayrollHead extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

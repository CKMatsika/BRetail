<?php

namespace App\Models;

class CustomerCredit extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'credit_limit' => 'decimal:2', 'terms_days' => 'integer'];
    }
}

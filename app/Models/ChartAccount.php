<?php

namespace App\Models;

class ChartAccount extends TenantRecord
{
    protected function casts(): array
    {
        return ['is_cash' => 'boolean', 'active' => 'boolean'];
    }
}

<?php

namespace App\Models;

class Currency extends TenantRecord
{
    protected function casts(): array
    {
        return ['minor_units' => 'integer', 'active' => 'boolean'];
    }
}

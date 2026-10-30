<?php

namespace App\Models;

class Supplier extends TenantRecord
{
    protected $attributes = ['active' => true, 'terms_days' => 30];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'terms_days' => 'integer'];
    }
}

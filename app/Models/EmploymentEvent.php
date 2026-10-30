<?php

namespace App\Models;

class EmploymentEvent extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'snapshot' => 'array', 'created_at' => 'datetime'];
    }
}

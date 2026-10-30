<?php

namespace App\Models;

class AccountingPeriod extends TenantRecord
{
    protected $attributes = ['locked' => false];

    protected function casts(): array
    {
        return ['locked' => 'boolean'];
    }
}

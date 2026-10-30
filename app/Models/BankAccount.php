<?php

namespace App\Models;

class BankAccount extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

<?php

namespace App\Models;

class TenantModule extends TenantRecord
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'enabled' => 'boolean'];
    }
}

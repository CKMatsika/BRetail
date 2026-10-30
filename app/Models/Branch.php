<?php

namespace App\Models;

class Branch extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
}

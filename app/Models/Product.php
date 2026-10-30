<?php

namespace App\Models;

class Product extends TenantRecord
{
    protected function casts(): array
    {
        return ['attributes' => 'array', 'active' => 'boolean'];
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}

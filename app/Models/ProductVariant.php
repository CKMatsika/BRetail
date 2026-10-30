<?php

namespace App\Models;

class ProductVariant extends TenantRecord
{
    protected function casts(): array
    {
        return ['attributes' => 'array', 'active' => 'boolean', 'cost_price' => 'decimal:4', 'selling_price' => 'decimal:4', 'wholesale_price' => 'decimal:4', 'reorder_level' => 'decimal:3', 'minimum_stock' => 'decimal:3', 'maximum_stock' => 'decimal:3'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

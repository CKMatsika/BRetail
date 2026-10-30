<?php

namespace App\Models;

class StockBalance extends TenantRecord
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'value' => 'decimal:4', 'average_cost' => 'decimal:4'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}

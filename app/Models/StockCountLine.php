<?php

namespace App\Models;

class StockCountLine extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}

<?php

namespace App\Models;

class StockMovement extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'value' => 'decimal:4'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function document()
    {
        return $this->belongsTo(StockDocument::class);
    }
}

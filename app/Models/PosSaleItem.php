<?php

namespace App\Models;

class PosSaleItem extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['cost_value'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:2', 'tax_rate' => 'decimal:4', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'net' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'cost_value' => 'decimal:4'];
    }
}

<?php

namespace App\Models;

class PosReturnItem extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['cost_value'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'net' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'cost_value' => 'decimal:4'];
    }
}

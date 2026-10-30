<?php

namespace App\Models;

class TradeAllocation extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}

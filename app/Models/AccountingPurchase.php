<?php

namespace App\Models;

class AccountingPurchase extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['items' => 'array', 'net' => 'decimal:4', 'tax' => 'decimal:4', 'total' => 'decimal:4'];
    }
}

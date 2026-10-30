<?php

namespace App\Models;

class PosPayment extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tendered' => 'decimal:2', 'change' => 'decimal:2'];
    }
}

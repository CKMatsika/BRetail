<?php

namespace App\Models;

class PosRefundPayment extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}

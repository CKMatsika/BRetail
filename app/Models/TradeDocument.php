<?php

namespace App\Models;

class TradeDocument extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['currency_snapshot'=>'array', 'items' => 'array', 'net' => 'decimal:4', 'tax' => 'decimal:4', 'total' => 'decimal:4'];
    }
}

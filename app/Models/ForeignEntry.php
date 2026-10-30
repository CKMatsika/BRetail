<?php

namespace App\Models;

class ForeignEntry extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'original_amount' => 'decimal:4', 'base_amount' => 'decimal:4'];
    }
}

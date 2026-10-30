<?php

namespace App\Models;

class PosCurrencyOperation extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['tenders' => 'array'];
    }
}

<?php

namespace App\Models;

class TransferEvent extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}

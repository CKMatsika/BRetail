<?php

namespace App\Models;

class StockDocument extends ImmutableInventoryRecord
{
    public $timestamps = false;

    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'document_id');
    }
}

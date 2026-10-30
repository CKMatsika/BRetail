<?php

namespace App\Models;

class StockCount extends TenantRecord
{
    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(StockCountLine::class, 'count_id');
    }
}

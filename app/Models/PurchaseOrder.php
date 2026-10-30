<?php

namespace App\Models;

class PurchaseOrder extends TenantRecord
{
    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['items' => 'array', 'currency_snapshot' => 'array', 'approved_at' => 'datetime'];
    }
}

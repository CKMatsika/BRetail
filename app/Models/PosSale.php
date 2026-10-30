<?php

namespace App\Models;

class PosSale extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['company_snapshot' => 'array', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'change' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PosSaleItem::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(PosPayment::class, 'sale_id');
    }

    public function returns()
    {
        return $this->hasMany(PosReturn::class, 'sale_id');
    }
}

<?php

namespace App\Models;

class PosReturn extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['net' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PosReturnItem::class, 'return_id');
    }

    public function payments()
    {
        return $this->hasMany(PosRefundPayment::class, 'return_id');
    }

    public function sale()
    {
        return $this->belongsTo(PosSale::class, 'sale_id');
    }
}

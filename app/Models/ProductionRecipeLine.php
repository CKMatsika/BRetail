<?php

namespace App\Models;

class ProductionRecipeLine extends TenantRecord
{
    protected function casts(): array { return ['quantity' => 'decimal:3']; }
    public function variant() { return $this->belongsTo(ProductVariant::class); }
}

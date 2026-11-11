<?php

namespace App\Models;

class ProductionOrder extends TenantRecord
{
    protected function casts(): array { return ['quantity' => 'decimal:3', 'material_cost' => 'decimal:4', 'completed_at' => 'datetime']; }
    public function recipe() { return $this->belongsTo(ProductionRecipe::class, 'recipe_id'); }
    public function lines() { return $this->hasMany(ProductionOrderLine::class, 'order_id'); }
}

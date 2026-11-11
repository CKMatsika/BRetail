<?php

namespace App\Models;

class ProductionRecipe extends TenantRecord
{
    protected function casts(): array { return ['active' => 'boolean', 'batch_quantity' => 'decimal:3']; }
    public function output() { return $this->belongsTo(ProductVariant::class, 'output_variant_id'); }
    public function lines() { return $this->hasMany(ProductionRecipeLine::class, 'recipe_id'); }
}

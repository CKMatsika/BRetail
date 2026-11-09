<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderPricingItem extends TenantRecord
{
    protected $table = 'tender_pricing_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'subtotal_cost' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'total_price' => 'decimal:2',
            'order_index' => 'integer',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(TenderPricingEstimate::class, 'tender_pricing_estimate_id');
    }
}

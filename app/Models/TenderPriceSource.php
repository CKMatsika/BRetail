<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderPriceSource extends TenantRecord
{
    protected $table = 'tender_price_sources';

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4', 'confidence' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function item(): BelongsTo { return $this->belongsTo(TenderPricingItem::class, 'pricing_item_id'); }
    public function document(): BelongsTo { return $this->belongsTo(TenderDocument::class, 'source_document_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'inventory_variant_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
}

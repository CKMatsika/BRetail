<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenderPricingEstimate extends TenantRecord
{
    protected $table = 'tender_pricing_estimates';

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:4',
            'exchange_risk_buffer_percent' => 'decimal:2',
            'is_split_currency' => 'boolean',
            'foreign_split_ratio' => 'decimal:2',
            'local_split_ratio' => 'decimal:2',
            'target_margin_percent' => 'decimal:2',
            'overheads_percent' => 'decimal:2',
            'vat_rate_percent' => 'decimal:2',
            'customs_duty_percent' => 'decimal:2',
            'withholding_tax_percent' => 'decimal:2',
            'praz_levy_percent' => 'decimal:2',
            'praz_bid_fee' => 'decimal:2',
            'bid_security_cost' => 'decimal:2',
            'other_statutory_costs' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenderPricingItem::class)->orderBy('order_index')->orderBy('id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}


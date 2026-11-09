<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderOutcome extends TenantRecord
{
    protected $fillable = [
        'tenant_id',
        'tender_id',
        'outcome',
        'awarded_at',
        'winning_bidder_name',
        'winning_price',
        'winning_currency',
        'price_variance_percent',
        'loss_reason',
        'debrief_notes',
        'contract_reference',
        'contract_signed_at',
        'final_margin_percent',
        'recorded_by_id',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
        'contract_signed_at' => 'datetime',
        'winning_price' => 'decimal:2',
        'price_variance_percent' => 'decimal:2',
        'final_margin_percent' => 'decimal:2',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}


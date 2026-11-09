<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderOpeningRecord extends TenantRecord
{
    protected $fillable = [
        'tenant_id',
        'tender_id',
        'attended_by_name',
        'opening_date',
        'venue',
        'minutes_notes',
        'competitor_bids',
    ];

    protected $casts = [
        'opening_date' => 'datetime',
        'competitor_bids' => 'array',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }
}

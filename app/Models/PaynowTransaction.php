<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaynowTransaction extends TenantRecord
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
        'raw_response' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(TradeDocument::class, 'document_id');
    }

    public function settlementDocument(): BelongsTo
    {
        return $this->belongsTo(TradeDocument::class, 'settlement_document_id');
    }
}


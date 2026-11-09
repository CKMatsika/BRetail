<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderClarification extends TenantRecord
{
    protected $table = 'tender_clarifications';

    protected function casts(): array
    {
        return [
            'submitted_or_received_at' => 'datetime',
            'response_due_at' => 'datetime',
            'response_received_at' => 'datetime',
            'affects_closing_date' => 'boolean',
            'new_closing_date' => 'datetime',
            'affects_pricing' => 'boolean',
            'affects_technical_specs' => 'boolean',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}


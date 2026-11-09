<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidStandardDocument extends TenantRecord
{
    protected $table = 'bid_standard_documents';

    protected function casts(): array
    {
        return [];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BidProposal::class, 'bid_proposal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

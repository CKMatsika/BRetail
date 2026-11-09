<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidProposalSection extends TenantRecord
{
    protected $table = 'bid_proposal_sections';

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
            'is_included' => 'boolean',
            'ai_generated' => 'boolean',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BidProposal::class, 'bid_proposal_id');
    }

    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_user_id');
    }
}

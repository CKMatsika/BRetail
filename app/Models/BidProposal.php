<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidProposal extends TenantRecord
{
    protected $table = 'bid_proposals';

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function leadAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_author_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BidProposalSection::class)->orderBy('order_index')->orderBy('id');
    }

    public function standardDocuments(): HasMany
    {
        return $this->hasMany(BidStandardDocument::class)->orderBy('id');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}


<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderRequirement extends TenantRecord
{
    protected $table = 'tender_requirements';

    protected $fillable = [
        'tenant_id',
        'tender_id',
        'tender_document_id',
        'category',
        'title',
        'original_wording',
        'simplified_explanation',
        'is_mandatory',
        'evidence_required',
        'source_document_name',
        'page_number',
        'verification_status',
        'confidence_score',
        'human_verified',
        'human_notes',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'page_number' => 'integer',
        'confidence_score' => 'decimal:2',
        'human_verified' => 'boolean',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(TenderDocument::class, 'tender_document_id');
    }
}

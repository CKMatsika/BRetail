<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderAnalysis extends TenantRecord
{
    protected $table = 'tender_analyses';

    protected $fillable = [
        'tenant_id',
        'tender_id',
        'status',
        'extracted_count',
        'mandatory_count',
        'statutory_count',
        'technical_count',
        'compliance_score',
        'eligibility_verdict',
        'executive_summary',
        'key_risks',
        'missing_evidence',
        'engine_used',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'extracted_count' => 'integer',
        'mandatory_count' => 'integer',
        'statutory_count' => 'integer',
        'technical_count' => 'integer',
        'compliance_score' => 'decimal:2',
        'key_risks' => 'array',
        'missing_evidence' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }
}

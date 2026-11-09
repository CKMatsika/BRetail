<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderSubmission extends TenantRecord
{
    protected $fillable = [
        'tenant_id',
        'tender_id',
        'submission_channel',
        'egp_reference_number',
        'physical_box_location',
        'delivered_by_name',
        'delivered_by_phone',
        'receipt_number',
        'submitted_at',
        'submitted_by_id',
        'technical_envelope_verified',
        'financial_envelope_verified',
        'bid_security_verified',
        'copies_count',
        'package_notes',
        'proof_attachment_path',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'technical_envelope_verified' => 'boolean',
        'financial_envelope_verified' => 'boolean',
        'bid_security_verified' => 'boolean',
        'copies_count' => 'integer',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }
}


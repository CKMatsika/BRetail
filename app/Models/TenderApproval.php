<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderApproval extends TenantRecord
{
    protected $fillable = [
        'tenant_id',
        'tender_id',
        'stage',
        'status',
        'approver_id',
        'approver_name',
        'approver_role',
        'comments',
        'conditions',
        'actioned_at',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderAlert extends TenantRecord
{
    protected $table = 'tender_alerts';

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(TenderMilestone::class, 'tender_milestone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

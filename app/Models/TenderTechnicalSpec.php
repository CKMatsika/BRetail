<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderTechnicalSpec extends TenantRecord
{
    protected $table = 'tender_technical_specs';

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'human_verified' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(TenderRequirement::class, 'tender_requirement_id');
    }
}

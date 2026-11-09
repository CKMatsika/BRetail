<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderGapAnalysis extends TenantRecord
{
    protected $table = 'tender_gap_analyses';

    protected function casts(): array
    {
        return [
            'overall_score' => 'integer',
            'is_mandatory_passed' => 'boolean',
            'fatal_flaws_count' => 'integer',
            'category_scores' => 'array',
            'fatal_flaws' => 'array',
            'action_plan' => 'array',
            'manual_override' => 'boolean',
            'override_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }
}

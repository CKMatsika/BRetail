<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tender extends TenantRecord
{
    protected $table = 'tenders';

    protected function casts(): array
    {
        return [
            'praz_categories' => 'array',
            'meta' => 'array',
            'bid_security_required' => 'boolean',
            'site_visit_required' => 'boolean',
            'estimated_value' => 'decimal:2',
            'tender_fee' => 'decimal:2',
            'bid_security_amount' => 'decimal:2',
            'closing_at' => 'datetime',
            'clarification_deadline' => 'datetime',
            'site_visit_at' => 'datetime',
            'internal_deadline' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class)->latest('id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(TenderRequirement::class)->orderBy('id');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(TenderAnalysis::class)->latest('id');
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(TenderAnalysis::class)->latestOfMany();
    }

    public function technicalSpecs(): HasMany
    {
        return $this->hasMany(TenderTechnicalSpec::class)->orderBy('order_index')->orderBy('id');
    }

    public function gapAnalyses(): HasMany
    {
        return $this->hasMany(TenderGapAnalysis::class)->latest('id');
    }

    public function latestGapAnalysis(): HasOne
    {
        return $this->hasOne(TenderGapAnalysis::class)->latestOfMany();
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(TenderMilestone::class)->orderBy('due_at');
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(TenderClarification::class)->orderByDesc('submitted_or_received_at');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(TenderAlert::class)->latest('id');
    }

    public function bidProposal(): HasOne
    {
        return $this->hasOne(BidProposal::class);
    }

    public function bidStandardDocuments(): HasMany
    {
        return $this->hasMany(BidStandardDocument::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding']);
    }

    public function isClosed(): bool
    {
        return $this->closing_at && Carbon::parse($this->closing_at)->isPast();
    }

    public function daysRemaining(): ?int
    {
        if (! $this->closing_at) {
            return null;
        }

        $now = Carbon::now();
        $closing = Carbon::parse($this->closing_at);

        return (int) $now->diffInDays($closing, false);
    }

    public function hoursRemaining(): ?int
    {
        if (! $this->closing_at) {
            return null;
        }

        $now = Carbon::now();
        $closing = Carbon::parse($this->closing_at);

        return (int) $now->diffInHours($closing, false);
    }
}


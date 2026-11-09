<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceDocument extends TenantRecord
{
    protected $table = 'compliance_documents';

    protected function casts(): array
    {
        return [
            'praz_categories' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'version' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocument::class, 'parent_document_id');
    }

    public function previousVersions(): HasMany
    {
        return $this->hasMany(ComplianceDocument::class, 'parent_document_id')->orderByDesc('version');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeCategory(Builder $q, string $category): Builder
    {
        return $q->where('category', $category);
    }

    public function calculateComputedStatus(?string $todayStr = null, int $warningDays = 30): string
    {
        if ($this->verification_status === 'not_applicable') {
            return 'not_applicable';
        }

        if (! $this->file_path) {
            return 'missing';
        }

        if ($this->expiry_date) {
            $today = $todayStr ? Carbon::parse($todayStr)->startOfDay() : Carbon::today();
            $expiry = Carbon::parse($this->expiry_date)->startOfDay();

            if ($expiry->lt($today)) {
                return 'expired';
            }

            if ($expiry->diffInDays($today, false) >= -$warningDays) {
                return 'expiring';
            }
        }

        if ($this->verification_status === 'pending_verification') {
            return 'pending_verification';
        }

        return 'valid';
    }

    public function daysUntilExpiry(?string $todayStr = null): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        $today = $todayStr ? Carbon::parse($todayStr)->startOfDay() : Carbon::today();
        $expiry = Carbon::parse($this->expiry_date)->startOfDay();

        return (int) $today->diffInDays($expiry, false);
    }

    public function isExpired(?string $todayStr = null): bool
    {
        return $this->calculateComputedStatus($todayStr) === 'expired';
    }
}


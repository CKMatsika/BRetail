<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenderMilestone extends TenantRecord
{
    protected $table = 'tender_milestones';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_lead_times' => 'array',
            'reminders_sent' => 'array',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(TenderAlert::class, 'tender_milestone_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPastDue(): bool
    {
        return ! $this->isCompleted() && $this->due_at && $this->due_at->isPast();
    }

    public function hoursRemaining(): ?int
    {
        if (! $this->due_at || $this->isCompleted()) {
            return null;
        }

        return (int) now()->diffInHours($this->due_at, false);
    }
}


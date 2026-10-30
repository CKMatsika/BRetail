<?php

namespace App\Models;

class LeaveRequest extends TenantRecord
{
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'days' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}

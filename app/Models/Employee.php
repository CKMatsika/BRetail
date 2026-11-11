<?php

namespace App\Models;

class Employee extends TenantRecord
{
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date', 'start_date' => 'date', 'end_date' => 'date',
            'national_id_number' => 'encrypted', 'nssa_number' => 'encrypted', 'zimra_tin' => 'encrypted',
            'zimbabwean_citizen' => 'boolean', 'ordinarily_resident_zimbabwe' => 'boolean', 'blind' => 'boolean', 'physically_disabled' => 'boolean',
            'disabled_dependents_count' => 'integer',
            'annual_leave_entitlement_days' => 'decimal:2',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager()
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports()
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function events()
    {
        return $this->hasMany(EmploymentEvent::class)->orderByDesc('effective_date')->orderByDesc('id');
    }
}

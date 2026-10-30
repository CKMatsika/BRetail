<?php

namespace App\Models;

class EmployeeCompensation extends TenantRecord
{
    protected $table = 'employee_compensations';

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return ['monthly_salary' => 'decimal:4', 'monthly_recurring_cash_earnings' => 'decimal:4', 'monthly_taxable_benefits' => 'decimal:4', 'monthly_allowable_deductions' => 'decimal:4', 'monthly_tax_credits' => 'decimal:4', 'monthly_cash_deductions' => 'decimal:4', 'nssa_eligible' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}

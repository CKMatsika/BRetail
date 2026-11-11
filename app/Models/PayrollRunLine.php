<?php

namespace App\Models;

class PayrollRunLine extends TenantRecord
{
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslipDeliveries()
    {
        return $this->hasMany(PayrollPayslipDelivery::class, 'payroll_run_line_id');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'gross_salary' => 'decimal:2', 'taxable_income' => 'decimal:2', 'paye' => 'decimal:2', 'aids_levy' => 'decimal:2', 'nssa_employee' => 'decimal:2', 'nssa_employer' => 'decimal:2', 'apwcs_employer' => 'decimal:2', 'net_pay' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Payroll calculation lines are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payroll calculation lines are immutable.'));
    }
}

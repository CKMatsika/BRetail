<?php

namespace App\Models;

class PayrollPayslipDelivery extends TenantRecord
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function runLine()
    {
        return $this->belongsTo(PayrollRunLine::class, 'payroll_run_line_id');
    }
}

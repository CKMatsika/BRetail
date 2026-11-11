<?php

namespace App\Models;

class PayrollRuleSet extends TenantRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'paye_bands' => 'array', 'daily_paye_bands' => 'array', 'aids_levy_rate' => 'decimal:4', 'nssa_employee_rate' => 'decimal:4', 'nssa_employer_rate' => 'decimal:4', 'nssa_ceiling' => 'decimal:4', 'apwcs_employer_rate' => 'decimal:4', 'annual_bonus_exempt_limit' => 'decimal:4', 'medical_aid_credit_rate' => 'decimal:4', 'approved_at' => 'datetime'];
    }
}

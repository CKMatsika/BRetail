<?php

namespace App\Models;

class BankReconciliation extends TenantRecord
{
    public $timestamps = false;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:4', 'closing_balance' => 'decimal:4', 'ledger_balance' => 'decimal:4', 'outstanding' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class, 'reconciliation_id');
    }
}

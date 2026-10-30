<?php

namespace App\Models;

class PayrollRunPosting extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['date' => 'date', 'created_at' => 'datetime', 'fx_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Payroll posting history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Payroll posting history is immutable.'));
    }
}

<?php

namespace App\Models;

class PayrollRun extends TenantRecord
{
    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'payment_date' => 'date', 'totals' => 'array', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function postings()
    {
        return $this->hasMany(PayrollRunPosting::class)->orderBy('id');
    }

    public function ruleSet()
    {
        return $this->belongsTo(PayrollRuleSet::class, 'rule_set_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($run) {
            if ($run->getOriginal('status') === 'approved') throw new \LogicException('Approved payroll runs are immutable.');
        });
        static::deleting(fn () => throw new \LogicException('Payroll runs cannot be deleted.'));
    }
}

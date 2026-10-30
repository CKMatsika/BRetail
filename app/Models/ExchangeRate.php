<?php

namespace App\Models;

class ExchangeRate extends TenantRecord
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:12'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($rate) {
            if ($rate->getOriginal('approved_at')) {
                throw new \LogicException('Approved exchange rates are immutable.');
            }
        });
        static::deleting(function ($rate) {
            if ($rate->approved_at) {
                throw new \LogicException('Approved exchange rates are immutable.');
            }
        });
    }
}

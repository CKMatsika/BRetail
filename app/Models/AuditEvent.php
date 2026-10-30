<?php

namespace App\Models;

class AuditEvent extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Audit events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit events are immutable.'));
    }
}

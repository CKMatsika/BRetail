<?php

namespace App\Models;

abstract class ImmutableInventoryRecord extends TenantRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Posted inventory is immutable.'));
        static::deleting(fn () => throw new \LogicException('Posted inventory is immutable.'));
    }
}

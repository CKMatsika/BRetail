<?php

namespace App\Models;

class JournalBatch extends TenantRecord
{
    public $timestamps = false;

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['payload_hash'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'batch_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($b) {
            if ($b->getOriginal('status') === 'posted') {
                throw new \LogicException('Posted journals are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Journal deletion is prohibited.'));
    }
}

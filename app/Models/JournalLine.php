<?php

namespace App\Models;

class JournalLine extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function batch()
    {
        return $this->belongsTo(JournalBatch::class, 'batch_id');
    }

    public function account()
    {
        return $this->belongsTo(ChartAccount::class, 'account_id');
    }
}

<?php

namespace App\Models;

class BankStatementLine extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function matches()
    {
        return $this->hasMany(BankMatch::class, 'statement_line_id');
    }
}

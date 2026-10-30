<?php

namespace App\Models;

class PosSession extends TenantRecord
{
    protected $attributes = ['status' => 'open'];

    protected $hidden = ['payload_hash', 'close_payload_hash'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['opening_cash' => 'decimal:2', 'counted_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'variance' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}

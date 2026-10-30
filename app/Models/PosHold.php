<?php

namespace App\Models;

class PosHold extends TenantRecord
{
    protected $attributes = ['status' => 'held'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['cart' => 'array'];
    }
}

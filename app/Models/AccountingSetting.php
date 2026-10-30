<?php

namespace App\Models;

class AccountingSetting extends TenantRecord
{
    public $timestamps = false;

    protected $attributes = ['live_enabled' => false];

    protected function casts(): array
    {
        return ['live_enabled' => 'boolean', 'cutover_stock' => 'array'];
    }
}

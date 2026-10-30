<?php

namespace App\Models;

class LoyaltyPromotion extends TenantRecord
{
    protected function casts(): array
    {
        return ['multiplier' => 'decimal:2', 'minimum_spend' => 'decimal:2', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'active' => 'boolean'];
    }
}

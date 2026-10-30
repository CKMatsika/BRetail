<?php

namespace App\Models;

class LoyaltyProgram extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'earn_points_per_currency' => 'decimal:3', 'value_per_point' => 'decimal:4', 'max_redemption_percent' => 'decimal:2', 'minimum_redemption_points' => 'decimal:3', 'expiry_days' => 'integer'];
    }
}

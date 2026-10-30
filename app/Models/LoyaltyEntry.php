<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyEntry extends TenantRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['points' => 'decimal:3', 'amount_base' => 'decimal:4', 'expires_on' => 'date:Y-m-d', 'occurred_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}

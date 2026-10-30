<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantSubscription extends Model
{
    protected $fillable = [
        'tenant_id', 'plan_name', 'status', 'starts_on', 'ends_on', 'trial_ends_on', 'grace_ends_on',
        'amount', 'currency', 'billing_reference', 'notes', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d',
            'trial_ends_on' => 'date:Y-m-d', 'grace_ends_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }
}

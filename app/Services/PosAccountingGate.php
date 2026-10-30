<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\TenantModule;

final class PosAccountingGate
{
    public function training(): bool
    {
        return config('pos.training_enabled') && app()->environment(['local', 'testing']);
    }

    public function mode(): string
    {
        if (app(TenantContext::class)->id && TenantModule::where('key', 'accounting')->where('enabled', true)->exists() && AccountingSetting::where('live_enabled', true)->exists()) {
            return 'live';
        }
        abort_unless($this->training(), 423, 'Configure accounting and explicitly activate live retail before checkout. Training requires an explicitly enabled local/test environment.');

        return 'training';
    }
}

<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\PosHold;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ModuleRegistry
{
    public function configure(string $key, bool $enabled, array $settings = []): void
    {
        DB::transaction(function () use ($key, $enabled, $settings) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $definition = config("modules.$key");
            if (! $definition) {
                abort(404);
            }
            $fail = fn ($message) => throw ValidationException::withMessages(['module' => $message]);
            if (! $enabled && in_array($key, ['purchasing', 'sales'], true)) {
                $kinds = $key === 'purchasing' ? ['receipt', 'bill', 'opening_payable'] : ['invoice', 'opening_receivable'];
                if (TradeDocument::whereIn('kind', $kinds)->exists() || ($key === 'purchasing' && PurchaseOrder::whereNotIn('status', ['cancelled', 'completed'])->exists())) {
                    $fail('Business documents depend on this module. Keep it enabled to preserve settlement and audit access.');
                }
            }
            if (!$enabled && in_array($key,['inventory','currencies'],true)) { app(\App\Services\PhaseSixAccess::class)->moduleClosable($key); }
            if ($key === 'accounting' && $enabled && PosSession::where('status', 'open')->exists()) {
                $fail('Close cashier shifts before enabling accounting.');
            }
            if ($key === 'accounting' && ! $enabled && AccountingSetting::exists()) {
                $fail('Initialized financial books must remain enabled to preserve posting continuity.');
            }
            if ($enabled && ! ($definition['available'] ?? $definition['phase'] === 1)) {
                $fail('This module is planned for a later phase.');
            }
            if (! $enabled && ($definition['required'] ?? false)) {
                $fail('This foundation module is required.');
            }
            if (! $enabled && $key === 'inventory' && StockCount::where('status', 'draft')->exists()) {
                $fail('Finish or cancel open stock counts before disabling inventory.');
            }
            if ($enabled) {
                if ($key === 'pos') {
                    app(PosAccountingGate::class)->mode();
                }
                foreach ($definition['depends'] as $dependency) {
                    if (! TenantModule::where('key', $dependency)->where('enabled', true)->exists()) {
                        $fail("Enable $dependency first.");
                    }
                }
            }
            if (! $enabled && $key === 'pos' && (PosSession::where('status', 'open')->exists() || PosHold::where('status', 'held')->exists())) {
                $fail('Close cashier shifts and consume or cancel held carts before disabling POS.');
            }
            if (! $enabled) {
                foreach (config('modules') as $other => $module) {
                    if (in_array($key, $module['depends'], true) && TenantModule::where('key', $other)->where('enabled', true)->exists()) {
                        $fail("$other depends on this module.");
                    }
                }
            }
            TenantModule::where('key', $key)->firstOrFail()->update(['enabled' => $enabled, 'settings' => $settings]);
            Audit::record('module.configured', $key, ['enabled' => $enabled, 'settings' => $settings]);
        });
    }
}

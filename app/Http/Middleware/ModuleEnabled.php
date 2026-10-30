<?php

namespace App\Http\Middleware;

use App\Models\TenantModule;
use Closure;
use Illuminate\Http\Request;

final class ModuleEnabled
{
    public function handle(Request $r, Closure $next, string $key)
    {
        abort_unless((config("modules.$key.available") ?? config("modules.$key.phase") === 1) && TenantModule::where('key', $key)->where('enabled', true)->exists(), 403, 'This module is unavailable.');

        return $next($r);
    }
}

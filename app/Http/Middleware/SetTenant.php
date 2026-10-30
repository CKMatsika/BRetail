<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

final class SetTenant
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->active && $r->user()?->tenant_id, 403);
        $context = app(TenantContext::class);
        $context->id = $r->user()->tenant_id;
        try {
            return $next($r);
        } finally {
            $context->id = null;
        }
    }
}

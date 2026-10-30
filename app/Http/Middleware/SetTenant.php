<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Services\SubscriptionAccess;
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
            $decision = app(SubscriptionAccess::class)->decision($r->user()->tenant_id);
            if (! $decision['allowed']) {
                if ($r->expectsJson()) {
                    return response()->json(['message' => $decision['message'], 'subscription_status' => $decision['status']], 423);
                }

                return redirect('/subscription');
            }
            return $next($r);
        } finally {
            $context->id = null;
        }
    }
}

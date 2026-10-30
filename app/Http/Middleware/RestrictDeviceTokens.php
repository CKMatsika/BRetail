<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class RestrictDeviceTokens
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && str_starts_with($token->name, 'device-sync:')) {
            abort_unless(
                $request->isMethod('post') && $request->is('api/v1/devices/heartbeat') && $token->can('device:heartbeat'),
                403,
                'This device credential can only send its sync heartbeat.'
            );
        }

        return $next($request);
    }
}

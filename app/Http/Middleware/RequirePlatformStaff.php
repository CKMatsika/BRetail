<?php

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use Closure;
use Illuminate\Http\Request;

final class RequirePlatformStaff
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->active && PlatformStaff::where('user_id', $request->user()->id)->where('active', true)->exists(), 403);

        return $next($request);
    }
}

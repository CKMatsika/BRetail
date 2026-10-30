<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class SubscriptionStatusController
{
    public function show(Request $request, SubscriptionAccess $access)
    {
        $user = $request->user();
        abort_unless($user?->tenant_id, 403);
        $decision = $access->decision((int) $user->tenant_id);
        if ($decision['allowed']) {
            return redirect('/dashboard');
        }
        $organization = DB::table('tenants as t')->leftJoin('companies as c', 'c.tenant_id', '=', 't.id')
            ->where('t.id', $user->tenant_id)->first(['t.name as tenant_name', 'c.name as company_name']);

        return Inertia::render('SubscriptionStatus', [
            'organization' => $organization?->company_name ?: $organization?->tenant_name,
            'status' => $decision['status'],
            'message' => $decision['message'],
            'support_email' => config('platform.support_email'),
            'platform_staff' => $user->isPlatformStaff(),
        ]);
    }
}

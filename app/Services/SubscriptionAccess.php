<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SubscriptionAccess
{
    public function decision(int $tenantId): array
    {
        $subscription = DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->first();
        if (! $subscription) {
            return ['allowed' => false, 'status' => 'pending', 'message' => 'This organization has no subscription record yet. Contact your BRetail account administrator.'];
        }

        $today = CarbonImmutable::today();
        $status = $subscription->status;
        $allowed = false;
        $message = 'This organization is not currently active. Contact your BRetail account administrator.';
        if ($subscription->starts_on && CarbonImmutable::parse($subscription->starts_on)->isAfter($today)) {
            $status = 'scheduled';
            $message = 'This subscription starts on '.CarbonImmutable::parse($subscription->starts_on)->format('j M Y').'.';
        } elseif ($subscription->status === 'active') {
            $allowed = ! $subscription->ends_on || ! CarbonImmutable::parse($subscription->ends_on)->endOfDay()->isBefore(now());
            if (! $allowed) {
                $status = 'expired';
                $message = 'This subscription has expired. Contact your BRetail account administrator to renew it.';
            }
        } elseif ($subscription->status === 'trialing') {
            $allowed = $subscription->trial_ends_on && ! CarbonImmutable::parse($subscription->trial_ends_on)->endOfDay()->isBefore(now());
            if (! $allowed) {
                $status = 'expired';
                $message = 'The trial period has ended. Contact your BRetail account administrator to activate a subscription.';
            }
        } elseif ($subscription->status === 'past_due') {
            $allowed = $subscription->grace_ends_on && ! CarbonImmutable::parse($subscription->grace_ends_on)->endOfDay()->isBefore(now());
            if ($allowed) {
                $message = 'Payment is overdue. Access remains available until '.CarbonImmutable::parse($subscription->grace_ends_on)->format('j M Y').'.';
            } else {
                $status = 'expired';
                $message = 'The payment grace period has ended. Contact your BRetail account administrator to restore access.';
            }
        }

        return ['allowed' => $allowed, 'status' => $status, 'message' => $message, 'subscription' => $subscription];
    }
}

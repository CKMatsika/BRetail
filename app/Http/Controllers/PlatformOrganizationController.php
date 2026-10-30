<?php

namespace App\Http\Controllers;

use App\Models\TenantSubscription;
use App\Services\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class PlatformOrganizationController
{
    public function index(Request $request, SubscriptionAccess $access)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:160']);
        $query = DB::table('tenants as t')
            ->leftJoin('companies as c', 'c.tenant_id', '=', 't.id')
            ->leftJoin('tenant_subscriptions as s', 's.tenant_id', '=', 't.id')
            ->select([
                't.id as tenant_id', 't.name as tenant_name', 't.created_at as onboarded_at',
                'c.name as company_name', 'c.settings as company_settings',
                's.plan_name', 's.status', 's.starts_on', 's.ends_on', 's.trial_ends_on', 's.grace_ends_on',
                's.amount', 's.currency', 's.billing_reference', 's.notes',
            ])
            ->selectSub(DB::table('users')->select('name')->whereColumn('tenant_id', 't.id')->orderBy('id')->limit(1), 'owner_name')
            ->selectSub(DB::table('users')->select('email')->whereColumn('tenant_id', 't.id')->orderBy('id')->limit(1), 'owner_email')
            ->selectSub(DB::table('users')->selectRaw('COUNT(*)')->whereColumn('tenant_id', 't.id'), 'user_count')
            ->selectSub(DB::table('branches')->selectRaw('COUNT(*)')->whereColumn('tenant_id', 't.id'), 'branch_count')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(function ($query) use ($like) {
                    $query->whereRaw('LOWER(t.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(c.name, \'\')) LIKE ?', [$like])
                        ->orWhereExists(fn ($users) => $users->selectRaw('1')->from('users')->whereColumn('users.tenant_id', 't.id')->whereRaw('LOWER(users.email) LIKE ?', [$like]));
                });
            })
            ->orderByDesc('t.id');

        $organizations = $query->paginate(50)->withQueryString()->through(function ($row) use ($access) {
            $settings = is_array($row->company_settings) ? $row->company_settings : (json_decode($row->company_settings ?? '[]', true) ?: []);
            $decision = $access->decision((int) $row->tenant_id);

            return [
                'tenant_id' => (int) $row->tenant_id,
                'name' => $row->company_name ?: $row->tenant_name,
                'owner_name' => $row->owner_name,
                'owner_email' => $row->owner_email,
                'contact_email' => $settings['contact_email'] ?? null,
                'branch_count' => (int) $row->branch_count,
                'user_count' => (int) $row->user_count,
                'onboarded_at' => $row->onboarded_at,
                'plan_name' => $row->plan_name ?? 'Unassigned',
                'status' => $row->status ?? 'pending',
                'effective_status' => $decision['status'],
                'starts_on' => $row->starts_on,
                'ends_on' => $row->ends_on,
                'trial_ends_on' => $row->trial_ends_on,
                'grace_ends_on' => $row->grace_ends_on,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'billing_reference' => $row->billing_reference,
                'notes' => $row->notes,
            ];
        });

        $activity = DB::table('platform_audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id')
            ->leftJoin('companies as c', 'c.tenant_id', '=', 't.id')
            ->orderByDesc('e.id')->limit(30)
            ->get(['e.id', 'e.action', 'e.reason', 'e.created_at', 'u.name as actor_name', 'c.name as company_name', 't.name as tenant_name']);

        return Inertia::render('PlatformAdmin', [
            'organizations' => $organizations,
            'filters' => ['search' => $filters['search'] ?? ''],
            'activity' => $activity,
            'user' => $request->user()->only('id', 'name', 'email'),
            'support_email' => config('platform.support_email'),
        ]);
    }

    public function updateSubscription(Request $request, string $tenantId, SubscriptionAccess $access)
    {
        $data = $request->validate([
            'plan_name' => 'required|string|max:100',
            'status' => ['required', Rule::in(['pending', 'trialing', 'active', 'past_due', 'suspended', 'cancelled'])],
            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'trial_ends_on' => 'nullable|date_format:Y-m-d',
            'grace_ends_on' => 'nullable|date_format:Y-m-d',
            'amount' => 'nullable|numeric|min:0|max:9999999999999999',
            'currency' => 'nullable|regex:/^[A-Z]{3}$/',
            'billing_reference' => 'nullable|string|max:160',
            'notes' => 'nullable|string|max:4000',
            'reason' => 'required|string|min:8|max:1000',
        ]);
        if ($data['status'] === 'trialing' && empty($data['trial_ends_on'])) {
            return back()->withErrors(['trial_ends_on' => 'Set an end date before enabling a trial.'])->withInput();
        }
        if ($data['status'] === 'past_due' && empty($data['grace_ends_on'])) {
            return back()->withErrors(['grace_ends_on' => 'Set a grace-period end date for overdue subscriptions.'])->withInput();
        }
        if (($data['amount'] ?? null) !== null && empty($data['currency'])) {
            return back()->withErrors(['currency' => 'Set the billing currency when recording a subscription amount.'])->withInput();
        }
        $tenantId = (int) $tenantId;
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        abort_unless($tenant, 404);

        DB::transaction(function () use ($request, $tenantId, $tenant, $data) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $before = DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $values = [
                'plan_name' => $data['plan_name'], 'status' => $data['status'],
                'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
                'trial_ends_on' => $data['trial_ends_on'] ?? null, 'grace_ends_on' => $data['grace_ends_on'] ?? null,
                'amount' => $data['amount'] ?? null, 'currency' => $data['currency'] ?? null,
                'billing_reference' => $data['billing_reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'updated_by_user_id' => $request->user()->id, 'updated_at' => now(),
            ];
            if ($before) {
                DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->update($values);
            } else {
                DB::table('tenant_subscriptions')->insert($values + ['tenant_id' => $tenantId, 'created_at' => now()]);
            }
            DB::table('platform_audit_events')->insert([
                'actor_user_id' => $request->user()->id, 'tenant_id' => $tenantId,
                'action' => 'subscription.updated', 'reason' => $data['reason'],
                'details' => json_encode(['organization' => $tenant->name, 'before' => $before, 'after' => $values], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        $decision = $access->decision($tenantId);

        return back()->with('success', 'Subscription saved. Organization access is '.$decision['status'].'.');
    }
}

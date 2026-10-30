<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltyPromotion;
use App\Models\PosCustomer;
use App\Models\TenantModule;
use App\Services\AccountingAccess;
use App\Services\Audit;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class LoyaltyController
{
    public function page(Request $request, LoyaltyService $loyalty)
    {
        abort_unless($request->user()->canDo('loyalty.view'), 403);
        $program = $loyalty->program();
        $branchIds = AccountingAccess::branches($request->user())->pluck('id');
        $customers = PosCustomer::orderBy('name')->limit(200)->get();
        $balances = $loyalty->balancesFor($customers);
        $entries = LoyaltyEntry::with('customer:id,name,loyalty_number')->whereIn('branch_id', $branchIds)->latest('id')->limit(50)->get()->map(fn ($entry) => [
            'id' => $entry->id, 'customer' => $entry->customer?->name, 'loyalty_number' => $entry->customer?->loyalty_number,
            'kind' => $entry->kind, 'points' => $entry->points, 'currency' => $entry->currency, 'amount_base' => $entry->amount_base,
            'reference' => $entry->sale_id ? 'Sale #'.$entry->sale_id : ($entry->return_id ? 'Return #'.$entry->return_id : '—'),
            'expires_on' => $entry->expires_on?->format('Y-m-d'), 'occurred_at' => $entry->occurred_at?->toIso8601String(),
        ]);
        $promotions = LoyaltyPromotion::orderByDesc('starts_on')->limit(50)->get();
        $company = Company::firstOrFail();

        return Inertia::render('Workspace', [
            'section' => 'loyalty',
            'loyalty' => [
                'program' => $program->only('active', 'earn_points_per_currency', 'value_per_point', 'max_redemption_percent', 'minimum_redemption_points', 'expiry_days', 'consent_version'),
                'base_currency' => $company->base_currency,
                'customers' => $customers->map(fn ($customer) => [
                    'id' => $customer->id, 'name' => $customer->name, 'loyalty_number' => $customer->loyalty_number,
                    'consented_at' => $customer->loyalty_consent_at, 'opted_out_at' => $customer->loyalty_opted_out_at,
                    'points' => $balances[$customer->id] ?? '0.000',
                ]),
                'entries' => $entries,
                'promotions' => $promotions->map(fn ($promotion) => $promotion->only('id', 'name', 'multiplier', 'minimum_spend', 'starts_on', 'ends_on', 'active')),
                'branches' => Branch::whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
                'enrolled_count' => PosCustomer::whereNotNull('loyalty_consent_at')->whereNull('loyalty_opted_out_at')->count(),
                'permissions' => $request->user()->role->permissions,
            ],
            'user' => $request->user()->only('id', 'name', 'email'),
            'role' => $request->user()->role->name,
            'permissions' => $request->user()->role->permissions,
            'company' => [...$company->only('name', 'industry', 'base_currency', 'accepted_currencies'), 'settings' => []],
            'branches' => Branch::whereIn('id', $branchIds)->with('warehouses')->get(),
            'modules' => TenantModule::get()->map(fn ($module) => [...$module->toArray(), ...config('modules.'.$module->key)]),
        ]);
    }

    public function program(Request $request, LoyaltyService $loyalty)
    {
        $data = $loyalty->updateProgram($request->user(), $request->all());

        return back()->with('success', 'Loyalty program rules saved.');
    }

    public function promotion(Request $request, LoyaltyService $loyalty, ?string $id = null)
    {
        $promotion = $loyalty->promotion($request->user(), $request->all(), $id ? (int) $id : null);

        return back()->with('success', 'Promotion saved.');
    }

    public function withdraw(Request $request, string $id, LoyaltyService $loyalty)
    {
        abort_unless($request->user()->canDo('loyalty.manage'), 403);
        $customer = PosCustomer::findOrFail($id);
        $loyalty->withdraw($customer, $request->user());

        return back()->with('success', 'Customer rewards consent withdrawn. Existing points remain on the append-only account and cannot be used while opted out.');
    }

    public function enroll(Request $request, string $id, LoyaltyService $loyalty)
    {
        abort_unless($request->user()->canDo('loyalty.manage'), 403);
        $customer = PosCustomer::findOrFail($id);
        $loyalty->enroll($customer, $request->user());

        return back()->with('success', 'Customer consent recorded and rewards enrollment activated.');
    }
}

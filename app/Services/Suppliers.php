<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Suppliers
{
    public function save(User $user, array $input, ?int $id = null): Supplier
    {
        return DB::transaction(function () use ($user, $input, $id) {
            AccountingAccess::lock($user, 'purchasing.configure');
            abort_unless(TenantModule::where('key', 'purchasing')->where('enabled', true)->exists(), 403);
            $supplier = $id ? Supplier::findOrFail($id) : new Supplier;
            $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
            $data = Validator::make($input, [
                'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('suppliers')->where('tenant_id', $user->tenant_id)->ignore($supplier->id)],
                'name' => 'required|string|max:160',
                'contact_name' => 'nullable|string|max:160',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:40',
                'address' => 'nullable|string|max:2000',
                'terms_days' => 'required|integer|min:0|max:365',
                'active' => 'required|boolean',
            ])->validate();
            $data['name'] = trim($data['name']);
            app(PartyDuplicates::class)->ensureUnique(Supplier::class, $data, $id ? $supplier : null);
            $supplier->fill($data)->save();
            Audit::record('purchasing.supplier_saved', (string) $supplier->id);

            return $supplier;
        }, 3);
    }
}

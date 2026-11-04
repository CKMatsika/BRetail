<?php

namespace App\Services;

use App\Models\CustomerCredit;
use App\Models\PosCustomer;
use App\Models\PosHold;
use App\Models\PosSale;
use App\Models\TradeDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Customers
{
    public function save(User $user, array $input, ?int $id = null): PosCustomer
    {
        $data = Validator::make($input, ['name' => 'required|string|max:160', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40'])->validate();
        $data['name'] = trim($data['name']);

        return DB::transaction(function () use ($user, $data, $id) {
            app(TradeLedger::class)->lock($user, 'sales.quote');
            $customer = $id ? PosCustomer::findOrFail($id) : new PosCustomer;
            app(PartyDuplicates::class)->ensureUnique(PosCustomer::class, $data, $id ? $customer : null);
            $customer->fill($data)->save();
            Audit::record($id ? 'sales.customer_updated' : 'sales.customer_created', (string) $customer->id);

            return $customer;
        }, 3);
    }

    public function delete(User $user, int $id): void
    {
        try {
            DB::transaction(function () use ($user, $id) {
                app(TradeLedger::class)->lock($user, 'sales.configure');
                $customer = PosCustomer::findOrFail($id);
                if (TradeDocument::where('customer_id', $id)->exists() || PosSale::where('customer_id', $id)->exists()
                    || PosHold::where('customer_id', $id)->exists()
                    || DB::table('loyalty_entries')->where('tenant_id', $user->tenant_id)->where('customer_id', $id)->exists()) {
                    throw ValidationException::withMessages(['customer' => 'This customer has linked documents, sales, held carts or loyalty history and cannot be deleted. Keep this record to preserve its history.']);
                }
                CustomerCredit::where('customer_id', $id)->delete();
                Audit::record('sales.customer_deleted', (string) $id, ['name' => $customer->name]);
                $customer->delete();
            }, 3);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['customer' => 'This customer is linked to existing records and cannot be deleted.']);
            }
            throw $e;
        }
    }
}

<?php

namespace App\Services;

use App\Models\PosCustomer;
use App\Models\PosHold;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PosHolds
{
    public function create(User $user, array $input): PosHold
    {
        InventoryAccess::permit($user, 'pos.sell');
        app(PosAccountingGate::class)->mode();
        $cart = app(PosCart::class)->validate($input);
        $meta = Validator::make($input, ['request_id' => 'required|uuid', 'label' => 'required|string|max:120'])->validate();
        $hash = PosMath::hash(['label' => $meta['label'], 'cart' => $cart, 'user_id' => $user->id]);

        return DB::transaction(function () use ($user, $cart, $meta, $hash) {
            app(PosAccess::class)->lock($user, 'pos.sell');
            InventoryAccess::warehouse($user, $cart['warehouse_id']);
            $old = PosHold::where('request_id', $meta['request_id'])->first();
            if ($old) {
                abort_unless($old->user_id === $user->id, 403);
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Held cart request ID conflict.']);
                }

                return $old;
            }
            app(PosCart::class)->quote($user, $cart);
            $h = PosHold::create(['request_id' => $meta['request_id'], 'payload_hash' => $hash, 'warehouse_id' => $cart['warehouse_id'], 'customer_id' => $cart['customer_id'], 'user_id' => $user->id, 'label' => $meta['label'], 'cart' => $cart]);
            Audit::record('pos.cart_held', (string) $h->id);

            return $h;
        });
    }

    public function cancel(User $user, int $id): void
    {
        InventoryAccess::permit($user, 'pos.sell');
        DB::transaction(function () use ($user, $id) {
            app(PosAccess::class)->lock($user, 'pos.sell');
            $h = PosHold::findOrFail($id);
            InventoryAccess::warehouse($user, $h->warehouse_id);
            abort_unless($h->user_id === $user->id || $user->canDo('pos.manage'), 403);
            if ($h->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'Only held carts can be cancelled.']);
            }$h->update(['status' => 'cancelled']);
            Audit::record('pos.hold_cancelled', (string) $h->id);
        });
    }

    public function customer(User $user, array $input): PosCustomer
    {
        InventoryAccess::permit($user, 'pos.customer');
        $d = Validator::make($input, ['name' => 'required|string|max:160', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40'])->validate();

        return DB::transaction(function () use ($d, $user) {
            app(PosAccess::class)->lock($user, 'pos.customer');
            $c = PosCustomer::create($d);
            Audit::record('pos.customer_created', (string) $c->id);

            return $c;
        });
    }
}

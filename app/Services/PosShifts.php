<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosCurrencyDrawer;
use App\Models\PosCurrencyOperation;
use App\Models\PosPayment;
use App\Models\PosRefundPayment;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PosShifts
{
    public function own(User $user, int $id, bool $open = true): PosSession
    {
        $s = PosSession::findOrFail($id);
        InventoryAccess::warehouse($user, $s->warehouse_id);
        abort_unless($s->user_id === $user->id, 403, 'Use your own cashier shift.');
        if ($open && $s->status !== 'open') {
            throw ValidationException::withMessages(['session' => 'This shift is closed. Open a new shift.']);
        }

        return $s;
    }

    public function open(User $user, array $input): PosSession
    {
        InventoryAccess::permit($user, 'pos.shift');
        app(PosAccountingGate::class)->mode();
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'warehouse_id' => 'required|integer', 'opening_cash' => ['required', 'string', PosMath::MONEY]])->validate();
        $hash = PosMath::hash(['warehouse_id' => (int) $d['warehouse_id'], 'opening_cash' => PosMath::money($d['opening_cash']), 'user_id' => $user->id]);

        return DB::transaction(function () use ($user, $d, $hash) {
            app(PosAccess::class)->lock($user, 'pos.shift');
            InventoryAccess::warehouse($user, $d['warehouse_id']);
            $existing = PosSession::where('request_id', $d['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 403);
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Shift request ID conflict.']);
                }

                return $existing;
            }
            if (PosSession::where('user_id', $user->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['session' => 'You already have an open shift.']);
            }$s = PosSession::create(['request_id' => $d['request_id'], 'payload_hash' => $hash, 'warehouse_id' => $d['warehouse_id'], 'user_id' => $user->id, 'opening_cash' => PosMath::money($d['opening_cash']), 'currency' => Company::firstOrFail()->base_currency, 'mode' => app(PosAccountingGate::class)->mode(), 'opened_at' => now()]);
            app(AccountingBridge::class)->openShift($user, $s);
            Audit::record('pos.shift_opened', (string) $s->id, ['opening_cash' => $s->opening_cash]);

            return $s;
        }, 3);
    }

    public function summary(PosSession $s): array
    {
        $sales = PosSale::where('session_id', $s->id)->pluck('id');
        $returns = PosReturn::where('session_id', $s->id)->pluck('id');
        $methods = [];
        $currencySales = PosMath::sum(PosCurrencyOperation::where('session_id', $s->id)->where('kind', 'sale')->pluck('foreign_base'));
        $currencyRefunds = PosMath::sum(PosCurrencyOperation::where('session_id', $s->id)->where('kind', 'refund')->pluck('foreign_base'));
        foreach (config('pos.methods') as $method) {
            $received = PosMath::sum(PosPayment::whereIn('sale_id', $sales)->where('method', $method)->pluck('amount'));
            $refunded = PosMath::sum(PosRefundPayment::whereIn('return_id', $returns)->where('method', $method)->pluck('amount'));
            if ($method === 'cash') {
                $received = PosMath::money(BigDecimal::of($received)->minus($currencySales));
                $refunded = PosMath::money(BigDecimal::of($refunded)->minus($currencyRefunds));
            }
            $methods[$method] = ['received' => $received, 'refunded' => $refunded, 'net' => PosMath::money(BigDecimal::of($received)->minus($refunded))];
        }

        return ['currency_drawers' => app(PosForeign::class)->summary($s), 'sale_count' => $sales->count(), 'return_count' => $returns->count(), 'gross_sales' => PosMath::sum(PosSale::whereIn('id', $sales)->pluck('total')), 'refunds' => PosMath::sum(PosReturn::whereIn('id', $returns)->pluck('total')), 'methods' => $methods, 'expected_cash' => PosMath::money(BigDecimal::of($s->opening_cash)->plus($methods['cash']['net']))];
    }

    public function close(User $user, int $id, array $input): PosSession
    {
        InventoryAccess::permit($user, 'pos.shift');
        app(PosAccountingGate::class)->mode();
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'counted_cash' => ['required', 'string', PosMath::MONEY], 'note' => 'nullable|string|max:2000'])->validate();
        $hash = PosMath::hash(['counted_cash' => PosMath::money($d['counted_cash']), 'note' => $d['note'] ?? null]);

        return DB::transaction(function () use ($user, $id, $d, $hash) {
            app(PosAccess::class)->lock($user, 'pos.shift');
            $s = PosSession::findOrFail($id);
            InventoryAccess::warehouse($user, $s->warehouse_id);
            abort_unless($s->user_id === $user->id || $user->canDo('pos.manage'), 403, 'Only this cashier or an authorized branch manager can close the shift.');
            if ($s->status === 'closed') {
                if ($s->close_request_id === $d['request_id'] && hash_equals($s->close_payload_hash, $hash)) {
                    return $s;
                }throw ValidationException::withMessages(['session' => 'This shift was already closed.']);
            }
            if (PosCurrencyDrawer::where('session_id', $s->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['session' => 'Count and close every currency drawer before closing this shift.']);
            }
            $summary = $this->summary($s);
            $s->update(['status' => 'closed', 'counted_cash' => PosMath::money($d['counted_cash']), 'expected_cash' => $summary['expected_cash'], 'variance' => PosMath::money(BigDecimal::of($d['counted_cash'])->minus($summary['expected_cash'])), 'close_request_id' => $d['request_id'], 'close_payload_hash' => $hash, 'closing_note' => $d['note'] ?? null, 'closed_at' => now(), 'closed_by' => $user->id]);
            app(AccountingBridge::class)->closeShift($user, $s);
            Audit::record('pos.shift_closed', (string) $s->id, ['expected_cash' => $s->expected_cash, 'counted_cash' => $s->counted_cash, 'variance' => $s->variance]);

            return $s;
        }, 3);
    }
}

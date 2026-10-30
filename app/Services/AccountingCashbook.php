<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\JournalBatch;
use App\Models\PostingLink;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingCashbook
{
    public function post(User $u, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.post');
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'book' => ['required', Rule::in(['live', 'training'])], 'branch_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'kind' => ['required', Rule::in(['receipt', 'payment', 'transfer'])], 'cash_account_id' => 'required|integer', 'offset_account_id' => 'required|integer|different:cash_account_id', 'amount' => ['required', 'string', PosMath::MONEY], 'reference' => 'required|string|max:160', 'description' => 'required|string|max:2000', 'cashflow' => ['required', Rule::in(['operating', 'investing', 'financing', 'none'])]])->validate();

        return DB::transaction(function () use ($u, $d) {
            AccountingAccess::lock($u, 'accounting.post');
            $l = app(AccountingLedger::class);
            $cash = ChartAccount::findOrFail($d['cash_account_id']);
            $offset = ChartAccount::findOrFail($d['offset_account_id']);
            $controlled = AccountMapping::whereIn('role', ['cash', 'inventory'])->pluck('account_id');
            if (! $cash->is_cash || ($d['kind'] === 'transfer' && ! $offset->is_cash) || ($d['kind'] !== 'transfer' && $offset->is_cash) || $controlled->contains($cash->id) || $controlled->contains($offset->id) || ! BigDecimal::of($d['amount'])->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Choose positive cash amounts and valid accounts. Drawer and inventory controls require business workflows.']);
            }
            // Let the ledger resolve retries before applying a balance check again.
            $existing = PostingLink::where(['book' => $d['book'], 'source_type' => 'cashbook', 'source_id' => $d['request_id']])->exists();
            if (! $existing && $d['kind'] !== 'receipt' && BigDecimal::of($l->balance($cash->id, $d['book'], $d['branch_id'], $d['date']))->isLessThan($d['amount'])) {
                throw ValidationException::withMessages(['amount' => 'Insufficient recorded cash on the posting date.']);
            }
            $receipt = $d['kind'] === 'receipt';
            $lines = [$l->line($cash->id, $receipt ? $d['amount'] : '0', $receipt ? '0' : $d['amount']), $l->line($offset->id, $receipt ? '0' : $d['amount'], $receipt ? $d['amount'] : '0')];

            return $l->system($u, ['book' => $d['book'], 'branch_id' => $d['branch_id'], 'date' => $d['date'], 'reference' => $d['reference'], 'description' => $d['description'], 'cashflow' => $d['kind'] === 'transfer' ? 'none' : $d['cashflow'], 'source_type' => 'cashbook', 'source_id' => $d['request_id'], 'lines' => $lines]);
        }, 3);
    }
}

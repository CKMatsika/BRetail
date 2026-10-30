<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingBanking
{
    public function bank(User $u, int $id): BankAccount
    {
        $b = BankAccount::findOrFail($id);
        if ($b->branch_id) {
            AccountingAccess::branch($u, $b->branch_id);
        } else {
            abort_unless($u->role->company_wide, 403, 'Company bank reconciliation requires company-wide access.');
        }

        return $b;
    }

    public function create(User $u, array $input): BankAccount
    {
        AccountingAccess::permit($u, 'accounting.bank');
        $d = Validator::make($input, ['name' => 'required|string|max:120', 'account_id' => 'required|integer', 'branch_id' => 'nullable|integer'])->validate();

        return DB::transaction(function () use ($u, $d) {
            AccountingAccess::lock($u, 'accounting.bank');
            if ($d['branch_id'] ?? null) {
                AccountingAccess::branch($u, $d['branch_id'], true);
            } else {
                abort_unless($u->role->company_wide, 403);
            }$a = ChartAccount::findOrFail($d['account_id']);
            if (! $a->active || ! $a->is_cash || $a->type !== 'asset' || AccountMapping::whereIn('role', ['inventory', 'cash', 'cash_safe'])->where('account_id', $a->id)->exists() || BankAccount::where('account_id', $a->id)->exists()) {
                throw ValidationException::withMessages(['account_id' => 'Choose an unused active cash asset account, excluding safes and drawers.']);
            }if (JournalLine::where('account_id', $a->id)->exists()) {
                throw ValidationException::withMessages(['account_id' => 'Register the bank before posting to this account.']);
            }$b = BankAccount::create([...$d, 'currency' => AccountingAccess::setting()->currency]);
            Audit::record('accounting.bank_created', (string) $b->id);

            return $b;
        });
    }

    public function csv(string $csv): array
    {
        if (strlen($csv) > 2000000) {
            throw ValidationException::withMessages(['csv' => 'Statement exceeds 2 MB.']);
        }$h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = fgetcsv($h, 0, ',', '"', '');
        if ($header !== ['date', 'reference', 'description', 'amount']) {
            throw ValidationException::withMessages(['csv' => 'Use the header date,reference,description,amount.']);
        }$rows = [];
        while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }if (count($row) !== 4 || count($rows) >= 5000) {
                throw ValidationException::withMessages(['csv' => 'Invalid statement row or more than 5000 rows.']);
            }$rows[] = array_combine($header, $row);
        }fclose($h);

        return $rows;
    }

    public function start(User $u, array $input): BankReconciliation
    {
        AccountingAccess::permit($u, 'accounting.bank');
        if (isset($input['csv'])) {
            $input['lines'] = $this->csv($input['csv']);
        }$signed = 'regex:/^-?\d{1,18}(\.\d{1,2})?$/';
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'bank_account_id' => 'required|integer', 'book' => ['required', Rule::in(['training', 'live'])], 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'opening_balance' => ['required', 'string', $signed], 'closing_balance' => ['required', 'string', $signed], 'baseline_confirmed' => 'required|accepted', 'lines' => 'present|array|max:5000', 'lines.*.date' => 'required|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:ends_on', 'lines.*.reference' => 'present|string|max:160', 'lines.*.description' => 'present|string|max:255', 'lines.*.amount' => ['required', 'string', $signed]])->validate();
        $hash = PosMath::hash($d);
        if ($d['ends_on'] > AccountingLedger::today()) {
            throw ValidationException::withMessages(['ends_on' => 'A bank statement cannot close future financial history.']);
        }

        return DB::transaction(function () use ($u, $d, $hash) {
            AccountingAccess::lock($u, 'accounting.bank');
            $b = $this->bank($u, $d['bank_account_id']);
            if (! $b->active) {
                throw ValidationException::withMessages(['bank' => 'Bank account is inactive.']);
            }if ($d['book'] === 'live' && ! AccountingAccess::setting()->live_enabled) {
                throw ValidationException::withMessages(['book' => 'Live accounting is not activated.']);
            }if ($old = BankReconciliation::where('request_id', $d['request_id'])->first()) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Statement request conflict.']);
                }

                return $old->load('lines.matches');
            }
            $q = BankReconciliation::where('bank_account_id', $b->id)->where('book', $d['book']);
            if ((clone $q)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['bank' => 'Finish or cancel the current statement first.']);
            }$previous = (clone $q)->where('status', 'closed')->orderByDesc('ends_on')->first();
            $baseline = $previous?->baseline_date ?? $d['starts_on'];
            $l = app(AccountingLedger::class);
            $opening = $previous?->closing_balance ?? $l->balance($b->account_id, $d['book'], $b->branch_id, null, $d['starts_on']);
            if ($previous && CarbonImmutable::parse($previous->ends_on)->addDay()->toDateString() !== $d['starts_on']) {
                throw ValidationException::withMessages(['starts_on' => 'Continue the previous statement on the next calendar day.']);
            }if (! BigDecimal::of($opening)->isEqualTo($d['opening_balance'])) {
                throw ValidationException::withMessages(['opening_balance' => 'Opening balance must equal the verified ledger baseline or previous statement closing balance.']);
            }$sum = BigDecimal::of($d['opening_balance']);
            foreach ($d['lines'] as $row) {
                if (BigDecimal::of($row['amount'])->isZero()) {
                    throw ValidationException::withMessages(['lines' => 'Statement amounts cannot be zero.']);
                }$sum = $sum->plus($row['amount']);
            }if (! $sum->isEqualTo($d['closing_balance'])) {
                throw ValidationException::withMessages(['closing_balance' => 'Opening plus statement movements does not equal closing balance.']);
            }
            $r = BankReconciliation::create([...collect($d)->except('lines', 'baseline_confirmed')->all(), 'baseline_date' => $baseline, 'payload_hash' => $hash, 'actor_id' => $u->id, 'created_at' => now()]);
            foreach ($d['lines'] as $i => $row) {
                BankStatementLine::create([...$row, 'reconciliation_id' => $r->id, 'position' => $i + 1]);
            }Audit::record('accounting.statement_imported', (string) $r->id);

            return $r->load('lines.matches');
        }, 3);
    }

    public function candidates(User $u, BankReconciliation $r)
    {
        $b = $this->bank($u, $r->bank_account_id);

        return JournalLine::with('batch')->where('account_id', $b->account_id)->whereHas('batch', fn ($q) => $q->where('status', 'posted')->where('book', $r->book)->whereBetween('date', [$r->baseline_date, $r->ends_on])->when($b->branch_id, fn ($q) => $q->where('branch_id', $b->branch_id)))->whereNotIn('id', BankMatch::select('journal_line_id'))->orderBy('id');
    }

    public function match(User $u, BankReconciliation $r, array $input): void
    {
        AccountingAccess::permit($u, 'accounting.bank');
        $d = Validator::make($input, ['statement_line_id' => 'required|integer', 'journal_line_ids' => 'required|array|min:1|max:200', 'journal_line_ids.*' => 'required|integer|distinct'])->validate();
        DB::transaction(function () use ($u, $r, $d) {
            AccountingAccess::lock($u, 'accounting.bank');
            $r->refresh();
            $this->bank($u, $r->bank_account_id);
            if ($r->status !== 'draft') {
                throw ValidationException::withMessages(['statement' => 'Closed/cancelled statements cannot be matched.']);
            }$s = $r->lines()->findOrFail($d['statement_line_id']);
            if ($s->matches()->exists()) {
                throw ValidationException::withMessages(['statement_line_id' => 'Unmatch this statement line before replacing its match.']);
            }$lines = $this->candidates($u, $r)->whereIn('id', $d['journal_line_ids'])->get();
            $sum = BigDecimal::zero();
            foreach ($lines as $l) {
                $sum = $sum->plus($l->debit)->minus($l->credit);
            }if ($lines->count() !== count($d['journal_line_ids']) || ! $sum->isEqualTo($s->amount)) {
                throw ValidationException::withMessages(['journal_line_ids' => 'Choose unmatched bank entries whose signed total equals the statement line.']);
            }foreach ($lines as $l) {
                BankMatch::create(['statement_line_id' => $s->id, 'journal_line_id' => $l->id, 'created_at' => now()]);
            }Audit::record('accounting.bank_matched', (string) $s->id);
        });
    }

    public function unmatch(User $u, BankReconciliation $r, int $id): void
    {
        AccountingAccess::permit($u, 'accounting.bank');
        DB::transaction(function () use ($u, $r, $id) {
            AccountingAccess::lock($u, 'accounting.bank');
            $r->refresh();
            $this->bank($u, $r->bank_account_id);
            if ($r->status !== 'draft') {
                abort(422, 'Only draft statements can be unmatched.');
            }$r->lines()->findOrFail($id)->matches()->delete();
            Audit::record('accounting.bank_unmatched', (string) $id);
        });
    }

    public function finish(User $u, BankReconciliation $r, bool $cancel = false): BankReconciliation
    {
        AccountingAccess::permit($u, 'accounting.bank');

        return DB::transaction(function () use ($u, $r, $cancel) {
            AccountingAccess::lock($u, 'accounting.bank');
            $r->refresh();
            $b = $this->bank($u, $r->bank_account_id);
            if ($r->status !== 'draft') {
                if (($cancel && $r->status === 'cancelled') || (! $cancel && $r->status === 'closed')) {
                    return $r;
                }abort(422, 'Statement is already finalized.');
            }if ($cancel) {
                BankMatch::whereIn('statement_line_id', $r->lines()->select('id'))->delete();
                $r->update(['status' => 'cancelled']);
            } else {
                if ($r->lines()->whereDoesntHave('matches')->exists()) {
                    throw ValidationException::withMessages(['statement' => 'Match every statement line before closing.']);
                }$ledger = app(AccountingLedger::class)->balance($b->account_id, $r->book, $b->branch_id, $r->ends_on);
                $out = BigDecimal::zero();
                foreach ($this->candidates($u, $r)->get() as $l) {
                    $out = $out->plus($l->debit)->minus($l->credit);
                }if (! BigDecimal::of($r->closing_balance)->plus($out)->isEqualTo($ledger)) {
                    throw ValidationException::withMessages(['statement' => 'Adjusted statement and ledger balances differ.']);
                }$r->update(['status' => 'closed', 'ledger_balance' => $ledger, 'outstanding' => (string) $out->toScale(4), 'closed_at' => now()]);
            }Audit::record($cancel ? 'accounting.statement_cancelled' : 'accounting.statement_closed', (string) $r->id);

            return $r;
        }, 3);
    }
}

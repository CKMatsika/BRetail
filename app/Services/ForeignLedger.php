<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ForeignAccount;
use App\Models\ForeignEntry;
use App\Models\PosCurrencyDrawer;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ForeignLedger
{
    public function lock(User $u, string $permission): void
    {
        AccountingAccess::lock($u, $permission);
        abort_unless(TenantModule::where('key', 'currencies')->where('enabled', true)->exists(), 403);
    }

    public function account(User $u, array $input): ForeignAccount
    {
        $d = Validator::make($input, ['name' => 'required|string|max:120', 'branch_id' => 'required|integer', 'currency_id' => 'required|integer', 'kind' => 'required|in:bank,safe,mobile,drawer,receivable,payable'])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'currencies.configure');
            abort_unless($u->role->company_wide, 403);
            AccountingAccess::branch($u, $d['branch_id'], true);
            $c = Currency::findOrFail($d['currency_id']);
            if (! $c->active || $c->code === Company::firstOrFail()->base_currency) {
                TradeLedger::fail('Choose an active foreign currency.');
            }$code = 'FX'.($c->id).'B'.$d['branch_id'].'N'.(ForeignAccount::max('id') + 1);
            while (ChartAccount::where('code', $code)->exists()) {
                $code .= 'X';
            }$a = ChartAccount::create(['code' => $code, 'name' => $d['name'].' ('.$c->code.')', 'type' => $d['kind'] === 'payable' ? 'liability' : 'asset', 'report_group' => in_array($d['kind'], ['receivable', 'payable']) ? 'Foreign trade' : 'Foreign cash', 'is_cash' => ! in_array($d['kind'], ['receivable', 'payable']), 'active' => true]);
            $f = ForeignAccount::create([...$d, 'account_id' => $a->id]);
            Audit::record('currency.account_created', (string) $f->id);

            return $f;
        });
    }

    public function mapping(string $role): int
    {
        $m = AccountMapping::where('role', $role)->first();
        if ($m) {
            return $m->account_id;
        }
        $income = $role === 'fx_gain';
        $code = $income ? 'FXGAIN' : 'FXLOSS';
        while (ChartAccount::where('code', $code)->exists()) {
            $code .= 'X';
        }$a = ChartAccount::create(['code' => $code, 'name' => $income ? 'Exchange gains' : 'Exchange losses', 'type' => $income ? 'income' : 'expense', 'report_group' => 'Exchange differences', 'is_cash' => false, 'active' => true]);
        AccountMapping::create(['role' => $role, 'account_id' => $a->id]);

        return $a->id;
    }

    public function balances(ForeignAccount $a, string $book, ?string $through = null): array
    {
        $q = ForeignEntry::where('foreign_account_id', $a->id)->where('book', $book);
        if ($through) {
            $q->where('date', '<=', $through);
        }$o = BigDecimal::zero();
        $b = BigDecimal::zero();
        foreach ($q->get() as $e) {
            $o = $o->plus($e->original_amount);
            $b = $b->plus($e->base_amount);
        }

        return ['original' => (string) $o->toScale(4), 'base' => (string) $b->toScale(4)];
    }

    private function date(ForeignAccount $a, string $book, string $date): void
    {
        app(TradeLedger::class)->date($date);
        if (ForeignEntry::where('foreign_account_id', $a->id)->where('book', $book)->where('date', '>', $date)->exists()) {
            TradeLedger::fail('Post foreign account entries in date order.');
        }
    }

    private function journal(User $u, ForeignAccount $a, ForeignEntry $e, array $lines, string $cashflow): void
    {
        app(AccountingLedger::class)->system($u, ['book' => $e->book, 'branch_id' => $a->branch_id, 'date' => $e->date, 'reference' => $e->reference, 'description' => 'Foreign currency '.$e->kind.' '.$a->name, 'source_type' => in_array($e->kind, ['revalue', 'reversal']) ? 'fx_revalue' : (($e->snapshot['trade_settlement'] ?? false) ? 'trade_fx_settlement' : 'fx_ledger'), 'source_id' => (string) $e->id, 'cashflow' => $cashflow, 'lines' => $lines]);
    }

    public function post(User $u, int $id, array $input, bool $trade = false, ?string $offsetBase = null, string $permission = 'currencies.post'): ForeignEntry
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:160', 'direction' => 'required|in:increase,decrease', 'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'], 'offset_account_id' => 'required|integer', 'cashflow' => 'required|in:operating,investing,financing'])->validate();

        return DB::transaction(function () use ($u, $id, $d, $trade, $offsetBase, $permission) {
            $this->lock($u, $permission);
            $a = ForeignAccount::findOrFail($id);
            if (PosCurrencyDrawer::where('foreign_account_id', $id)->where('status', 'open')->exists()) {
                TradeLedger::fail('Use the cashier workflow for an open POS currency drawer.');
            }
            AccountingAccess::branch($u, $a->branch_id, true);
            $hash = PosMath::hash([$id, $d, $trade, $offsetBase]);
            $old = ForeignEntry::where('request_id', $d['request_id'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    TradeLedger::fail('Foreign entry request conflict.');
                }

                return $old;
            }$book = AccountingAccess::book();
            $this->date($a, $book, $d['date']);
            $offset = ChartAccount::findOrFail($d['offset_account_id']);
            if (! $offset->active || $offset->id === $a->account_id || ForeignAccount::where('account_id', $offset->id)->exists() || AccountMapping::whereIn('role', $trade ? ['inventory', 'cash', 'receipt_accrual', 'inventory_transit', 'branch_clearing'] : ['inventory', 'cash', 'payable', 'receivable', 'receipt_accrual', 'inventory_transit', 'branch_clearing'])->where('account_id', $offset->id)->exists()) {
                TradeLedger::fail('Choose an active non-control offset account.');
            }
            $snap = app(CurrencyRates::class)->snapshot(Currency::findOrFail($a->currency_id)->code, $d['date'], $d['amount']);
            $snap['trade_settlement'] = $trade;
            $amount = BigDecimal::of($snap['original_amount']);
            if (! $amount->isPositive()) {
                TradeLedger::fail('Amount must be positive.');
            }$balance = $this->balances($a, $book);
            $increase = $d['direction'] === 'increase';
            $current = BigDecimal::of($snap['base_amount']);
            if (! $current->isPositive()) {
                TradeLedger::fail('Amount is too small to post in base currency.');
            }
            if (! $increase && $amount->isGreaterThan($balance['original'])) {
                TradeLedger::fail('Amount exceeds original currency balance.');
            }
            if ($increase && $a->kind !== 'payable' && $offset->is_cash && BigDecimal::of(app(AccountingLedger::class)->balance($offset->id, $book, $a->branch_id, $d['date']))->isLessThan($current)) {
                TradeLedger::fail('Insufficient recorded funds in the funding account.');
            }
            $carrying = $increase ? $current : ($amount->isEqualTo($balance['original']) ? BigDecimal::of($balance['base']) : BigDecimal::of($balance['base'])->multipliedBy($amount)->dividedBy($balance['original'], 4, RoundingMode::HalfUp));
            $e = ForeignEntry::create(['foreign_account_id' => $id, 'book' => $book, 'request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => $increase ? 'increase' : 'decrease', 'date' => $d['date'], 'reference' => $d['reference'], 'original_amount' => (string) ($increase ? $amount : $amount->negated()), 'base_amount' => (string) ($increase ? $carrying : $carrying->negated()), 'snapshot' => $snap, 'created_at' => now()]);
            $l = app(AccountingLedger::class);
            $asset = $a->kind !== 'payable';
            $debit = $increase === $asset;
            $offsetValue = $offsetBase ?? (string) $current;
            $lines = [$l->line($a->account_id, $debit ? (string) $carrying : '0', $debit ? '0' : (string) $carrying), $l->line($offset->id, $debit ? '0' : $offsetValue, $debit ? $offsetValue : '0')];
            $delta = BigDecimal::of(PosMath::sum(array_column($lines, 'debit'), 4))->minus(PosMath::sum(array_column($lines, 'credit'), 4));
            if (! $delta->isZero()) {
                $gain = $delta->isPositive();
                $lines[] = $l->line($this->mapping($gain ? 'fx_gain' : 'fx_loss'), $gain ? '0' : (string) $delta->abs(), $gain ? (string) $delta->abs() : '0');
            }
            $this->journal($u, $a, $e, $lines, $d['cashflow']);
            Audit::record('currency.entry_posted', (string) $e->id);

            return $e;
        }, 3);
    }

    public function payrollBankDecrease(User $u, int $id, array $input, string $liabilityBaseAmount): ForeignEntry
    {
        return $this->post($u, $id, [
            ...$input,
            'direction' => 'decrease',
            'offset_account_id' => app(AccountingLedger::class)->account('payroll_net_payable')->id,
            'cashflow' => 'operating',
        ], false, $liabilityBaseAmount, 'payroll.runs.settle');
    }

    public function revalue(User $u, int $id, array $input): ForeignEntry
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:160'])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $this->lock($u, 'currencies.revalue');
            $a = ForeignAccount::findOrFail($id);
            if (PosCurrencyDrawer::where('foreign_account_id', $id)->where('status', 'open')->exists()) {
                TradeLedger::fail('Use the cashier workflow for an open POS currency drawer.');
            }
            AccountingAccess::branch($u, $a->branch_id, true);
            $book = AccountingAccess::book();
            $hash = PosMath::hash(['revalue', $id, $d]);
            $old = ForeignEntry::where('request_id', $d['request_id'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    TradeLedger::fail('Revaluation request conflict.');
                }

                return $old;
            }$this->date($a, $book, $d['date']);
            if (ForeignEntry::where('foreign_account_id', $id)->where('book', $book)->where('kind', 'revalue')->where('date', $d['date'])->exists()) {
                TradeLedger::fail('This account was already revalued on this date.');
            }$b = $this->balances($a, $book);
            $snap = app(CurrencyRates::class)->snapshot(Currency::findOrFail($a->currency_id)->code, $d['date'], $b['original']);
            $delta = BigDecimal::of($snap['base_amount'])->minus($b['base']);
            $e = ForeignEntry::create(['foreign_account_id' => $id, 'book' => $book, 'request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => 'revalue', 'date' => $d['date'], 'reference' => $d['reference'], 'original_amount' => '0', 'base_amount' => (string) $delta, 'snapshot' => $snap, 'created_at' => now()]);
            $l = app(AccountingLedger::class);
            $gain = ($a->kind !== 'payable') === $delta->isPositive();
            $value = (string) $delta->abs();
            $this->journal($u, $a, $e, [$l->line($a->account_id, $gain ? $value : '0', $gain ? '0' : $value), $l->line($this->mapping($gain ? 'fx_gain' : 'fx_loss'), $gain ? '0' : $value, $gain ? $value : '0')], 'none');

            return $e;
        }, 3);
    }

    public function reverseRevaluation(User $u, int $id, array $input): ForeignEntry
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:160'])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $this->lock($u, 'currencies.revalue');
            $original = ForeignEntry::where('kind', 'revalue')->findOrFail($id);
            $a = ForeignAccount::findOrFail($original->foreign_account_id);
            AccountingAccess::branch($u, $a->branch_id, true);
            if ($original->book !== AccountingAccess::book()) {
                TradeLedger::fail('Revaluation belongs to another book.');
            }$hash = PosMath::hash(['reverse', $id, $d]);
            $old = ForeignEntry::where('request_id', $d['request_id'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    TradeLedger::fail('Reversal request conflict.');
                }

                return $old;
            }if (ForeignEntry::where('foreign_account_id', $a->id)->where('book', $original->book)->where('id', '>', $id)->exists()) {
                TradeLedger::fail('Only the latest account event can be reversed.');
            }$this->date($a, $original->book, $d['date']);
            $delta = BigDecimal::of($original->base_amount)->negated();
            $e = ForeignEntry::create(['foreign_account_id' => $a->id, 'book' => $original->book, 'request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => 'reversal', 'date' => $d['date'], 'reference' => $d['reference'], 'original_amount' => '0', 'base_amount' => (string) $delta, 'snapshot' => $original->snapshot, 'reverses_id' => $id, 'created_at' => now()]);
            $l = app(AccountingLedger::class);
            $wasGain = ($a->kind !== 'payable') === BigDecimal::of($original->base_amount)->isPositive();
            $value = (string) $delta->abs();
            $this->journal($u, $a, $e, [$l->line($a->account_id, $wasGain ? '0' : $value, $wasGain ? $value : '0'), $l->line($this->mapping($wasGain ? 'fx_gain' : 'fx_loss'), $wasGain ? $value : '0', $wasGain ? '0' : $value)], 'none');

            return $e;
        });
    }
}

<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Currency;
use App\Models\ForeignAccount;
use App\Models\ForeignEntry;
use App\Models\PosCurrencyDrawer;
use App\Models\PosCurrencyOperation;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PosForeign
{
    private function lock(User $u, string $permission): void
    {
        app(PosAccess::class)->lock($u, $permission);
        app(ForeignLedger::class)->lock($u, $permission);
    }

    private function value(string $v): string
    {
        return (string) BigDecimal::of($v)->toScale(4);
    }

    private function account(PosCurrencyDrawer $drawer): ForeignAccount
    {
        return ForeignAccount::findOrFail($drawer->foreign_account_id);
    }

    private function ensureDate(ForeignAccount $a, string $book): void
    {
        if (ForeignEntry::where('foreign_account_id', $a->id)->where('book', $book)->where('date', '>', AccountingLedger::today())->exists()) {
            TradeLedger::fail('Foreign account has later entries.');
        }
    }

    private function entry(ForeignAccount $a, PosSession $s, string $original, string $base, string $kind, string $ref, array $snap = []): ForeignEntry
    {
        $this->ensureDate($a, $s->mode);
        $id = (string) Str::uuid();

        return ForeignEntry::create(['foreign_account_id' => $a->id, 'book' => $s->mode, 'request_id' => $id, 'payload_hash' => PosMath::hash([$id, $original, $base]), 'kind' => $kind, 'date' => AccountingLedger::today(), 'reference' => $ref, 'original_amount' => $original, 'base_amount' => $base, 'snapshot' => $snap, 'created_at' => now()]);
    }

    private function journal(User $u, PosSession $s, string $ref, string $id, array $lines): void
    {
        app(AccountingLedger::class)->system($u, ['book' => $s->mode, 'branch_id' => Warehouse::findOrFail($s->warehouse_id)->branch_id, 'date' => AccountingLedger::today(), 'reference' => $ref, 'description' => 'POS foreign currency movement', 'cashflow' => 'operating', 'source_type' => 'fx_ledger', 'source_id' => 'POS-'.$id, 'lines' => $lines]);
    }

    private function carrying(ForeignAccount $a, PosSession $s, string $amount): string
    {
        $b = app(ForeignLedger::class)->balances($a, $s->mode);
        $q = BigDecimal::of($amount);
        if ($q->isGreaterThan($b['original'])) {
            TradeLedger::fail('Insufficient original currency in drawer or safe.');
        }if ($q->isZero()) {
            return '0.0000';
        }

        return $q->isEqualTo($b['original']) ? $b['base'] : (string) BigDecimal::of($b['base'])->multipliedBy($q)->dividedBy($b['original'], 4, RoundingMode::HalfUp);
    }

    public function openDrawer(User $u, array $input): PosCurrencyDrawer
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'session_id' => 'required|integer', 'safe_account_id' => 'required|integer', 'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/']])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'pos.shift');
            $s = app(PosShifts::class)->own($u, $d['session_id']);
            $hash = PosMath::hash($d);
            if ($old = PosCurrencyDrawer::where('request_id', $d['request_id'])->first()) {
                if ($old->payload_hash !== $hash) {
                    TradeLedger::fail('Drawer request conflict.');
                }

                return $old;
            }$safe = ForeignAccount::findOrFail($d['safe_account_id']);
            if (! in_array($safe->kind, ['safe', 'bank', 'mobile']) || $safe->branch_id !== Warehouse::findOrFail($s->warehouse_id)->branch_id) {
                TradeLedger::fail('Choose a foreign safe in the shift branch.');
            }$currency = Currency::findOrFail($safe->currency_id);
            $snap = app(CurrencyRates::class)->snapshot($currency->code, AccountingLedger::today(), $d['amount']);
            if (PosCurrencyDrawer::where('session_id', $s->id)->whereHas('account', fn ($q) => $q->where('currency_id', $currency->id))->exists()) {
                TradeLedger::fail('This shift already has a drawer in that currency.');
            }$base = $this->carrying($safe, $s, $d['amount']);
            $name = 'Shift '.$s->id.' '.$currency->code.' drawer';
            $code = 'PFX'.Str::upper(Str::random(16));
            $chart = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => 'asset', 'report_group' => 'Foreign cash', 'is_cash' => true, 'active' => true]);
            $account = ForeignAccount::create(['branch_id' => $safe->branch_id, 'currency_id' => $currency->id, 'name' => $name, 'kind' => 'drawer', 'account_id' => $chart->id]);
            $drawer = PosCurrencyDrawer::create(['session_id' => $s->id, 'foreign_account_id' => $account->id, 'safe_account_id' => $safe->id, 'opening_amount' => $d['amount'], 'request_id' => $d['request_id'], 'payload_hash' => $hash]);
            $e = $this->entry($safe, $s, (string) BigDecimal::of($d['amount'])->negated(), (string) BigDecimal::of($base)->negated(), 'pos_float', 'SHIFT-'.$s->id, $snap);
            $this->entry($account, $s, $d['amount'], $base, 'pos_float', 'SHIFT-'.$s->id, $snap);
            $l = app(AccountingLedger::class);
            $this->journal($u, $s, 'SHIFT-'.$s->id, (string) $e->id, [$l->line($account->account_id, $base), $l->line($safe->account_id, '0', $base)]);

            return $drawer;
        });
    }

    public function summary(PosSession $s): array
    {
        return PosCurrencyDrawer::where('session_id', $s->id)->get()->map(function ($d) use ($s) {
            $a = $this->account($d);

            return [...$d->toArray(), 'currency' => Currency::findOrFail($a->currency_id)->code, 'balance' => app(ForeignLedger::class)->balances($a, $s->mode), 'rate' => app(CurrencyRates::class)->snapshot(Currency::findOrFail($a->currency_id)->code, AccountingLedger::today(), '1')['exchange_rate']];
        })->all();
    }

    public function closeDrawer(User $u, int $id, array $input): PosCurrencyDrawer
    {
        $d = Validator::make($input, ['counted_amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/']])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $this->lock($u, 'pos.shift');
            $drawer = PosCurrencyDrawer::findOrFail($id);
            $s = app(PosShifts::class)->own($u, $drawer->session_id);
            if ($drawer->status === 'closed') {
                if (BigDecimal::of($drawer->counted_amount)->isEqualTo($d['counted_amount'])) {
                    return $drawer;
                }TradeLedger::fail('Drawer is already counted.');
            }$a = $this->account($drawer);
            $safe = ForeignAccount::findOrFail($drawer->safe_account_id);
            $currency = Currency::findOrFail($a->currency_id);
            $snap = app(CurrencyRates::class)->snapshot($currency->code, AccountingLedger::today(), $d['counted_amount']);
            $b = app(ForeignLedger::class)->balances($a, $s->mode);
            $count = BigDecimal::of($d['counted_amount']);
            $expected = BigDecimal::of($b['original']);
            $difference = $count->minus($expected);
            $baseDiff = $difference->isZero() ? BigDecimal::zero() : ($expected->isPositive() ? BigDecimal::of($b['base'])->multipliedBy($difference)->dividedBy($expected, 4, RoundingMode::HalfUp) : BigDecimal::of($snap['base_amount']));
            $safeBase = BigDecimal::of($b['base'])->plus($baseDiff);
            $e = $this->entry($a, $s, (string) $expected->negated(), (string) BigDecimal::of($b['base'])->negated(), 'pos_close', 'SHIFT-'.$s->id, $snap);
            $this->entry($safe, $s, (string) $count, (string) $safeBase, 'pos_close', 'SHIFT-'.$s->id, $snap);
            $l = app(AccountingLedger::class);
            $lines = [$l->line($safe->account_id, (string) $safeBase), $l->line($a->account_id, '0', $b['base'])];
            if (! $baseDiff->isZero()) {
                $lines[] = $baseDiff->isPositive() ? $l->line('cash_over', '0', (string) $baseDiff) : $l->line('cash_short', (string) $baseDiff->abs());
            }$this->journal($u, $s, 'SHIFT-'.$s->id, (string) $e->id, $lines);
            $drawer->update(['status' => 'closed', 'counted_amount' => (string) $count, 'expected_amount' => (string) $expected]);

            return $drawer;
        });
    }

    public function checkout(User $u, array $input): PosSale
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'session_id' => 'required|integer', 'foreign_tenders' => 'required|array|min:1|max:10', 'foreign_tenders.*.drawer_id' => 'required|integer|distinct', 'foreign_tenders.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'], 'change_drawer_id' => 'nullable|integer', 'payments' => 'present|array'])->validate();

        return DB::transaction(function () use ($u, $input, $d) {
            $this->lock($u, 'pos.sell');
            $hash = PosMath::hash($input);
            if ($old = PosCurrencyOperation::where('request_id', $d['request_id'])->first()) {
                if ($old->payload_hash !== $hash || $old->kind !== 'sale') {
                    TradeLedger::fail('Currency sale request conflict.');
                }$sale = app(PosAccess::class)->sale($u, $old->sale_id);

                return $sale->load('items', 'payments');
            }$s = app(PosShifts::class)->own($u, $d['session_id']);
            $payments = $d['payments'] ? app(PosCart::class)->payments($d['payments']) : [];
            $total = BigDecimal::of(PosMath::sum(array_column($payments, 'amount')));
            $rows = [];
            foreach ($d['foreign_tenders'] as $t) {
                $drawer = PosCurrencyDrawer::where('session_id', $s->id)->where('status', 'open')->findOrFail($t['drawer_id']);
                $a = $this->account($drawer);
                $snap = app(CurrencyRates::class)->snapshot(Currency::findOrFail($a->currency_id)->code, AccountingLedger::today(), $t['amount']);
                $base = PosMath::money($snap['base_amount']);
                if (! BigDecimal::of($base)->isPositive()) {
                    TradeLedger::fail('Tender must have positive base value.');
                }$rows[] = ['drawer_id' => $drawer->id, 'original_tendered' => $snap['original_amount'], 'original_change' => '0', 'original_amount' => $snap['original_amount'], 'base_amount' => $base, 'snapshot' => $snap];
                $total = $total->plus($base);
            }
            $quote = app(PosCart::class)->quote($u, $input);
            $change = $total->minus($quote['total']);
            if ($change->isNegative()) {
                TradeLedger::fail('Tenders do not cover total.');
            }
            if (! ($d['change_drawer_id'] ?? null) && $change->isGreaterThan(BigDecimal::of(app(PosShifts::class)->summary($s)['expected_cash'])->plus(collect($payments)->firstWhere('method', 'cash')['amount'] ?? '0'))) {
                TradeLedger::fail('Base drawer has insufficient physical cash for change.');
            }
            if ($d['change_drawer_id'] ?? null) {
                $found = false;
                foreach ($rows as &$r) {
                    if ($r['drawer_id'] === $d['change_drawer_id']) {
                        $found = true;
                        $currency = Currency::where('code', $r['snapshot']['currency'])->firstOrFail();
                        try {
                            $originalChange = $change->dividedBy($r['snapshot']['exchange_rate'], $currency->minor_units, RoundingMode::Unnecessary);
                        } catch (\Throwable) {
                            TradeLedger::fail('Change cannot be represented exactly in selected currency. Choose base change.');
                        }if ($originalChange->isGreaterThan($r['original_tendered'])) {
                            TradeLedger::fail('Change exceeds selected tender.');
                        }$r['original_change'] = (string) $originalChange;
                        $r['original_amount'] = (string) BigDecimal::of($r['original_tendered'])->minus($originalChange);
                        $r['base_amount'] = PosMath::money(BigDecimal::of($r['base_amount'])->minus($change));
                    }
                }unset($r);
                if (! $found) {
                    TradeLedger::fail('Select a tendered currency for change.');
                }
            }
            $foreignTender = PosMath::sum(array_map(fn ($r) => PosMath::money($r['snapshot']['base_amount']), $rows));
            $cash = collect($payments)->firstWhere('method', 'cash');
            $payments = array_values(array_filter($payments, fn ($p) => $p['method'] !== 'cash'));
            $payments[] = ['method' => 'cash', 'amount' => PosMath::money(BigDecimal::of($foreignTender)->plus($cash['amount'] ?? '0')), 'reference' => null];
            $sale = app(PosSales::class)->checkout($u, [...$input, 'payments' => $payments]);
            $l = app(AccountingLedger::class);
            $foreignBase = BigDecimal::zero();
            foreach ($rows as $r) {
                $drawer = PosCurrencyDrawer::findOrFail($r['drawer_id']);
                $a = $this->account($drawer);
                $e = $this->entry($a, $s, $r['original_amount'], $r['base_amount'], 'pos_sale', $sale->receipt_number, $r['snapshot']);
                $this->journal($u, $s, $sale->receipt_number, (string) $e->id, [$l->line($a->account_id, $r['base_amount']), $l->line('cash', '0', $r['base_amount'])]);
                $foreignBase = $foreignBase->plus($r['base_amount']);
            }
            PosCurrencyOperation::create(['request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => 'sale', 'session_id' => $s->id, 'sale_id' => $sale->id, 'tenders' => $rows, 'foreign_base' => (string) $foreignBase, 'created_at' => now()]);

            return $sale;
        }, 3);
    }

    public function refund(User $u, PosSale $sale, array $input): PosReturn
    {
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'session_id' => 'required|integer', 'foreign_refunds' => 'present|array|max:10', 'foreign_refunds.*.source_drawer_id' => 'required|integer|distinct', 'foreign_refunds.*.drawer_id' => 'required|integer', 'foreign_refunds.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'], 'payments' => 'present|array'])->validate();

        return DB::transaction(function () use ($u, $sale, $input, $d) {
            $this->lock($u, 'pos.refund');
            app(PosAccess::class)->sale($u, $sale->id);
            $hash = PosMath::hash($input);
            if ($old = PosCurrencyOperation::where('request_id', $d['request_id'])->first()) {
                if ($old->payload_hash !== $hash || $old->kind !== 'refund' || $old->sale_id !== $sale->id) {
                    TradeLedger::fail('Currency refund request conflict.');
                }

                return PosReturn::with('items', 'payments')->findOrFail($old->return_id);
            }$s = app(PosShifts::class)->own($u, $d['session_id']);
            $source = PosCurrencyOperation::where('sale_id', $sale->id)->where('kind', 'sale')->firstOrFail();
            $payments = $d['payments'] ? app(PosCart::class)->payments($d['payments']) : [];
            $rows = [];
            $total = BigDecimal::zero();
            $nativeCash = BigDecimal::of(app(PosAccess::class)->detail($sale)['remaining_payments']['cash'] ?? '0')->minus($source->foreign_base)->plus(PosMath::sum(PosCurrencyOperation::where('sale_id', $sale->id)->where('kind', 'refund')->pluck('foreign_base')));
            if (BigDecimal::of(collect($payments)->firstWhere('method', 'cash')['amount'] ?? '0')->isGreaterThan($nativeCash)) {
                TradeLedger::fail('Base cash refund exceeds original base cash remaining.');
            }$nativeCash = BigDecimal::of(app(PosAccess::class)->detail($sale)['remaining_payments']['cash'] ?? '0')->minus($source->foreign_base)->plus(PosMath::sum(PosCurrencyOperation::where('sale_id', $sale->id)->where('kind', 'refund')->pluck('foreign_base')));
            if (BigDecimal::of(collect($payments)->firstWhere('method', 'cash')['amount'] ?? '0')->isGreaterThan($nativeCash)) {
                TradeLedger::fail('Base cash refund exceeds original base cash remaining.');
            }
            foreach ($d['foreign_refunds'] as $r) {
                $original = collect($source->tenders)->firstWhere('drawer_id', $r['source_drawer_id']);
                if (! $original) {
                    TradeLedger::fail('Choose an original currency tender.');
                }$priorOriginal = BigDecimal::zero();
                $priorBase = BigDecimal::zero();
                foreach (PosCurrencyOperation::where('sale_id', $sale->id)->where('kind', 'refund')->get() as $op) {
                    foreach ($op->tenders as $t) {
                        if ($t['source_drawer_id'] == $r['source_drawer_id']) {
                            $priorOriginal = $priorOriginal->plus($t['original_amount']);
                            $priorBase = $priorBase->plus($t['base_amount']);
                        }
                    }
                }
                $amount = BigDecimal::of($r['amount']);
                $left = BigDecimal::of($original['original_amount'])->minus($priorOriginal);
                if (! $amount->isPositive() || $amount->isGreaterThan($left)) {
                    TradeLedger::fail('Refund exceeds original tender remaining.');
                }$currency = Currency::where('code', $original['snapshot']['currency'])->firstOrFail();
                try {
                    $amount = $amount->toScale($currency->minor_units);
                } catch (\Throwable) {
                    TradeLedger::fail('Refund exceeds currency precision.');
                }$drawer = PosCurrencyDrawer::where('session_id', $s->id)->where('status', 'open')->findOrFail($r['drawer_id']);
                $a = $this->account($drawer);
                if ($a->currency_id !== $currency->id) {
                    TradeLedger::fail('Refund drawer must use original currency.');
                }$carrying = $this->carrying($a, $s, (string) $amount);
                $baseLeft = BigDecimal::of($original['base_amount'])->minus($priorBase);
                $base = $amount->isEqualTo($left) ? (string) $baseLeft : (string) $baseLeft->multipliedBy($amount)->dividedBy($left, 2, RoundingMode::HalfUp);
                $rows[] = ['drawer_id' => $drawer->id, 'source_drawer_id' => (int) $r['source_drawer_id'], 'original_amount' => (string) $amount, 'base_amount' => $base, 'carrying' => $carrying, 'snapshot' => $original['snapshot']];
                $total = $total->plus($base);
            }
            $cash = collect($payments)->firstWhere('method', 'cash');
            $payments = array_values(array_filter($payments, fn ($p) => $p['method'] !== 'cash'));
            $combined = BigDecimal::of($cash['amount'] ?? '0')->plus($total);
            if ($combined->isPositive()) {
                $payments[] = ['method' => 'cash', 'amount' => PosMath::money($combined), 'reference' => null];
            }$return = app(PosReturns::class)->post($u, $sale, [...$input, 'payments' => $payments], true, (string) $total);
            $l = app(AccountingLedger::class);
            foreach ($rows as $r) {
                $a = $this->account(PosCurrencyDrawer::findOrFail($r['drawer_id']));
                $e = $this->entry($a, $s, (string) BigDecimal::of($r['original_amount'])->negated(), (string) BigDecimal::of($r['carrying'])->negated(), 'pos_refund', $return->receipt_number, $r['snapshot']);
                $lines = [$l->line('cash', $r['base_amount']), $l->line($a->account_id, '0', $r['carrying'])];
                $difference = BigDecimal::of($r['base_amount'])->minus($r['carrying']);
                if (! $difference->isZero()) {
                    $lines[] = $difference->isPositive() ? $l->line(app(ForeignLedger::class)->mapping('fx_gain'), '0', (string) $difference) : $l->line(app(ForeignLedger::class)->mapping('fx_loss'), (string) $difference->abs());
                }$this->journal($u, $s, $return->receipt_number, (string) $e->id, $lines);
            }
            PosCurrencyOperation::create(['request_id' => $d['request_id'], 'payload_hash' => $hash, 'kind' => 'refund', 'session_id' => $s->id, 'sale_id' => $sale->id, 'return_id' => $return->id, 'tenders' => $rows, 'foreign_base' => (string) $total, 'created_at' => now()]);

            return $return;
        }, 3);
    }
}

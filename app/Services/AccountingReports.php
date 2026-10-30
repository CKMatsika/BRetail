<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AccountingReports
{
    public function run(User $u, array $input): array
    {
        return DB::transaction(function () use ($u, $input) {
            AccountingAccess::lock($u, 'accounting.view');

            return $this->snapshot($u, $input);
        });
    }

    private function snapshot(User $u, array $input): array
    {
        AccountingAccess::permit($u, 'accounting.view');
        $d = Validator::make($input, ['book' => ['required', Rule::in(['training', 'live'])], 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'branch_id' => 'nullable|integer'])->validate();
        $branches = AccountingAccess::branches($u)->pluck('id');
        if ($d['branch_id'] ?? null) {
            AccountingAccess::branch($u, $d['branch_id']);
            $branches = collect([(int) $d['branch_id']]);
        }
        $rows = [];
        foreach (ChartAccount::orderBy('code')->get() as $a) {
            $rows[$a->id] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'group' => $a->report_group, 'is_cash' => $a->is_cash, 'opening' => '0.0000', 'debit' => '0.0000', 'credit' => '0.0000', 'closing' => '0.0000'];
        }$gl = [];
        $fxEffect = BigDecimal::zero();
        $flows = ['operating' => '0.0000', 'investing' => '0.0000', 'financing' => '0.0000', 'none' => '0.0000'];
        $q = JournalLine::with('batch')->whereHas('batch', fn ($q) => $q->where('status', 'posted')->where('book', $d['book'])->whereIn('branch_id', $branches)->where('date', '<=', $d['to']));
        foreach ($q->lazyById(500) as $l) {
            $r = &$rows[$l->account_id];
            $delta = BigDecimal::of($l->debit)->minus($l->credit);
            $r['closing'] = (string) BigDecimal::of($r['closing'])->plus($delta)->toScale(4);
            if ($l->batch->date < $d['from']) {
                $r['opening'] = (string) BigDecimal::of($r['opening'])->plus($delta)->toScale(4);
            } else {
                $r['debit'] = (string) BigDecimal::of($r['debit'])->plus($l->debit)->toScale(4);
                $r['credit'] = (string) BigDecimal::of($r['credit'])->plus($l->credit)->toScale(4);
                $gl[] = ['id' => $l->id, 'journal' => $l->batch->number, 'date' => $l->batch->date, 'branch_id' => $l->batch->branch_id, 'account' => $r['code'].' '.$r['name'], 'reference' => $l->batch->reference, 'description' => $l->description ?? $l->batch->description, 'debit' => $l->debit, 'credit' => $l->credit];
                if ($r['is_cash'] && $l->batch->source_type === 'fx_revalue') $fxEffect = $fxEffect->plus($delta);
                if ($r['is_cash'] && $l->batch->source_type !== 'fx_revalue') {
                    $flows[$l->batch->cashflow] = (string) BigDecimal::of($flows[$l->batch->cashflow])->plus($delta)->toScale(4);
                }
            }unset($r);
        }
        $totals = ['asset' => BigDecimal::zero(), 'liability' => BigDecimal::zero(), 'equity' => BigDecimal::zero(), 'income' => BigDecimal::zero(), 'expense' => BigDecimal::zero()];
        $income = BigDecimal::zero();
        $expense = BigDecimal::zero();
        $openingCash = BigDecimal::zero();
        $closingCash = BigDecimal::zero();
        foreach ($rows as $r) {
            $totals[$r['type']] = $totals[$r['type']]->plus($r['closing']);
            $movement = BigDecimal::of($r['debit'])->minus($r['credit']);
            if ($r['type'] === 'income') {
                $income = $income->minus($movement);
            }if ($r['type'] === 'expense') {
                $expense = $expense->plus($movement);
            }if ($r['is_cash']) {
                $openingCash = $openingCash->plus($r['opening']);
                $closingCash = $closingCash->plus($r['closing']);
            }
        }
        $earnings = $totals['income']->negated()->minus($totals['expense']);
        $liabilities = $totals['liability']->negated();
        $equity = $totals['equity']->negated()->plus($earnings);
        $inventory = AccountMapping::where('role', 'inventory')->first();
        $stock = app(AccountingSetup::class)->inventoryValues();
        $s = AccountingAccess::setting();
        $snapshot = $d['book'] === 'training' && $s->live_enabled ? ($s->cutover_stock ?? []) : $stock;
        $physical = BigDecimal::zero();
        foreach ($snapshot as $branch => $value) {
            if ($branches->contains((int) $branch)) {
                $physical = $physical->plus($value);
            }
        }$invLedger = $inventory ? ($rows[$inventory->account_id]['closing'] ?? '0.0000') : '0.0000';
        $inventoryCurrent = $d['to'] === AccountingLedger::today();
        usort($gl, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return ['filters' => $d, 'trial_balance' => array_values($rows), 'general_ledger' => $gl, 'profit_loss' => ['income' => (string) $income->toScale(4), 'expense' => (string) $expense->toScale(4), 'profit' => (string) $income->minus($expense)->toScale(4)], 'balance_sheet' => ['assets' => (string) $totals['asset']->toScale(4), 'liabilities' => (string) $liabilities->toScale(4), 'equity_including_earnings' => (string) $equity->toScale(4), 'unclosed_earnings' => (string) $earnings->toScale(4), 'difference' => (string) $totals['asset']->minus($liabilities)->minus($equity)->toScale(4)], 'cash_flow' => ['opening' => (string) $openingCash->toScale(4), 'closing' => (string) $closingCash->toScale(4), 'movement' => (string) $closingCash->minus($openingCash)->toScale(4), 'exchange_effect' => (string) $fxEffect->toScale(4), 'categories' => $flows], 'inventory_reconciliation' => ['ledger' => $invLedger, 'stock' => $inventoryCurrent ? (string) $physical->toScale(4) : null, 'difference' => $inventoryCurrent ? (string) BigDecimal::of($invLedger)->minus($physical)->toScale(4) : null, 'basis' => $d['book'] === 'training' && $s->live_enabled ? 'Frozen inventory at live cutover' : 'Current stock; compare only at today’s date']];
    }
}

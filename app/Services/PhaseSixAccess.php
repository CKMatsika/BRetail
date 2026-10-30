<?php

namespace App\Services;

use App\Models\BranchTransfer;
use App\Models\ForeignAccount;
use App\Models\ForeignEntry;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;

final class PhaseSixAccess
{
    public function branchClosable(int $branch): void
    {
        $w = Warehouse::where('branch_id', $branch)->pluck('id');
        if (BranchTransfer::whereNotIn('status', ['completed', 'cancelled'])->where(fn ($q) => $q->whereIn('source_id', $w)->orWhereIn('destination_id', $w))->exists()) {
            TradeLedger::fail('Complete or cancel branch transfers before closing the branch.');
        }
        foreach (ForeignAccount::where('branch_id', $branch)->get() as $a) {
            foreach (['training', 'live'] as $book) {
                $b = app(ForeignLedger::class)->balances($a, $book);
                if (! BigDecimal::of($b['original'])->isZero() || ! BigDecimal::of($b['base'])->isZero()) {
                    TradeLedger::fail('Settle foreign account balances before closing the branch.');
                }
            }
        }
    }

    public function moduleClosable(string $key): void
    {
        if ($key === 'inventory' && BranchTransfer::whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            TradeLedger::fail('Resolve unfinished branch transfers first.');
        }
        if ($key === 'currencies' && ForeignEntry::exists()) {
            TradeLedger::fail('Keep currencies enabled to preserve settlement and historical reporting.');
        }
    }
}

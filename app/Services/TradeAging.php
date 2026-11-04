<?php

namespace App\Services;

use App\Models\TenantModule;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TradeAging
{
    public function run(User $user, array $input = []): array
    {
        return DB::transaction(function () use ($user, $input) {
            AccountingAccess::lock($user);
            $data = Validator::make($input, ['to' => 'nullable|date_format:Y-m-d|before_or_equal:'.AccountingLedger::today(), 'book' => ['nullable', Rule::in(['training', 'live'])], 'branch_id' => 'nullable|integer'])->validate();
            $date = $data['to'] ?? AccountingLedger::today();
            $book = $data['book'] ?? AccountingAccess::book();
            $allowed = [];
            foreach (['sales' => ['invoice', 'opening_receivable'], 'purchasing' => ['bill', 'opening_payable']] as $module => $kinds) {
                if ($user->canDo($module.'.view') && TenantModule::where('key', $module)->where('enabled', true)->exists()) {
                    $allowed = [...$allowed, ...$kinds];
                }
            }
            abort_unless($allowed, 403);
            $branches = AccountingAccess::branches($user);
            if (! empty($data['branch_id'])) {
                AccountingAccess::branch($user, (int) $data['branch_id']);
                $branches->whereKey($data['branch_id']);
            }
            $branches = $branches->get(['id', 'name']);
            $warehouses = Warehouse::whereIn('branch_id', $branches->pluck('id'))->get(['id', 'branch_id']);
            // Select the version effective at the cutoff, rather than today's current version.
            $documents = TradeDocument::where('book', $book)->whereIn('kind', $allowed)
                ->whereIn('warehouse_id', $warehouses->pluck('id'))->where('date', '<=', $date)
                ->whereNotExists(function ($q) use ($date) {
                    $q->selectRaw('1')->from('trade_documents as next_version')
                        ->whereColumn('next_version.tenant_id', 'trade_documents.tenant_id')
                        ->whereColumn('next_version.parent_id', 'trade_documents.id')
                        ->whereColumn('next_version.kind', 'trade_documents.kind')->where('next_version.date', '<=', $date);
                })->orderBy('due_on')->orderBy('id')->get();
            $allocations = TradeAllocation::whereIn('target_id', $documents->pluck('id'))
                ->whereIn('document_id', TradeDocument::where('book', $book)->where('date', '<=', $date)->select('id'))
                ->get()->groupBy('target_id');
            $rows = [];
            $parties = [];
            $blank = ['current' => '0.0000', '1–30' => '0.0000', '31–60' => '0.0000', '61–90' => '0.0000', '90+' => '0.0000', 'total' => '0.0000'];
            $totals = ['receivable' => $blank, 'payable' => $blank];
            foreach ($documents as $doc) {
                $allocated = $allocations->get($doc->id, collect());
                $outstanding = BigDecimal::of($doc->total)->minus(PosMath::sum($allocated->pluck('amount'), 4));
                if (! $outstanding->isPositive()) {
                    continue;
                }
                $days = $doc->due_on ? max(0, (int) CarbonImmutable::parse($doc->due_on)->diffInDays(CarbonImmutable::parse($date), false)) : 0;
                $bucket = $days === 0 ? 'current' : ($days <= 30 ? '1–30' : ($days <= 60 ? '31–60' : ($days <= 90 ? '61–90' : '90+')));
                $role = in_array($doc->kind, ['invoice', 'opening_receivable'], true) ? 'receivable' : 'payable';
                $partyId = $role === 'receivable' ? $doc->customer_id : $doc->supplier_id;
                $key = $role.':'.$partyId;
                $parties[$key] ??= [...$blank, 'key' => $key, 'party_id' => $partyId, 'party_name' => $doc->party_name, 'role' => $role, 'documents' => 0];
                foreach ([$bucket, 'total'] as $column) {
                    $parties[$key][$column] = (string) BigDecimal::of($parties[$key][$column])->plus($outstanding)->toScale(4);
                    $totals[$role][$column] = (string) BigDecimal::of($totals[$role][$column])->plus($outstanding)->toScale(4);
                }
                $parties[$key]['documents']++;
                $original = $doc->currency_snapshot ? (string) BigDecimal::of($doc->currency_snapshot['original_amount'])->minus(PosMath::sum($allocated->pluck('original_amount')->filter(fn ($v) => $v !== null), 4))->toScale(4) : null;
                $branchId = $warehouses->firstWhere('id', $doc->warehouse_id)->branch_id;
                $rows[] = [...$doc->only('id', 'kind', 'party_name', 'date', 'due_on', 'total', 'warehouse_id'), 'number' => $doc->display_number, 'party_key' => $key, 'role' => $role, 'branch_id' => $branchId, 'branch_name' => $branches->firstWhere('id', $branchId)->name, 'outstanding' => (string) $outstanding->toScale(4), 'original_currency' => $doc->currency_snapshot['currency'] ?? null, 'original_outstanding' => $original, 'days_overdue' => $days, 'bucket' => $bucket];
            }
            $controls = [];
            $ledger = app(AccountingLedger::class);
            foreach (['receivable' => 'invoice', 'payable' => 'bill'] as $role => $kind) {
                if (! in_array($kind, $allowed, true)) {
                    continue;
                }
                foreach ($branches as $branch) {
                    $balance = BigDecimal::of($ledger->balance($ledger->account($role)->id, $book, $branch->id, $date));
                    if ($role === 'payable') {
                        $balance = $balance->negated();
                    }
                    $sub = PosMath::sum(array_column(array_filter($rows, fn ($r) => $r['role'] === $role && $r['branch_id'] === $branch->id), 'outstanding'), 4);
                    $controls[] = ['role' => $role, 'branch_id' => $branch->id, 'branch_name' => $branch->name, 'ledger' => (string) $balance->toScale(4), 'subledger' => $sub, 'unallocated' => (string) $balance->minus($sub)->toScale(4)];
                }
            }

            return ['book' => $book, 'date' => $date, 'aging' => $rows, 'parties' => array_values($parties), 'totals' => $totals, 'controls' => $controls];
        });
    }
}

<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\JournalBatch;
use App\Models\PostingLink;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingCashbook
{
    public function post(User $u, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.post');
        $d = Validator::make($input, [
            'request_id' => 'required|uuid',
            'book' => ['required', Rule::in(['live', 'training'])],
            'branch_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'kind' => ['required', Rule::in(['receipt', 'payment', 'transfer'])],
            'cash_account_id' => 'required|integer',
            'offset_account_id' => 'required|integer|different:cash_account_id',
            'amount' => ['required', 'string', PosMath::MONEY],
            'reference' => 'required|string|max:160',
            'description' => 'required|string|max:2000',
            'cashflow' => ['required', Rule::in(['operating', 'investing', 'financing', 'none'])],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
            'allow_overdraft' => ['sometimes', 'boolean'],
        ])->validate();

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
            if (! $existing && empty($d['allow_overdraft']) && $d['kind'] !== 'receipt' && BigDecimal::of($l->balance($cash->id, $d['book'], $d['branch_id'], $d['date']))->isLessThan($d['amount'])) {
                throw ValidationException::withMessages(['amount' => 'Insufficient recorded cash on the posting date.']);
            }
            $receipt = $d['kind'] === 'receipt';
            $taxRate = isset($d['tax_rate']) && BigDecimal::of($d['tax_rate'])->isPositive() ? BigDecimal::of($d['tax_rate']) : null;

            if ($taxRate !== null && $d['kind'] !== 'transfer') {
                $total = BigDecimal::of($d['amount']);
                $multiplier = BigDecimal::of('1')->plus($taxRate->dividedBy('100', 6, RoundingMode::HalfUp));
                $net = $total->dividedBy($multiplier, 2, RoundingMode::HalfUp);
                $tax = $total->minus($net);
                if ($receipt) {
                    $lines = [
                        $l->line($cash->id, (string) $total),
                        $l->line($offset->id, '0', (string) $net),
                        $l->line('tax_payable', '0', (string) $tax),
                    ];
                } else {
                    $lines = [
                        $l->line($offset->id, (string) $net),
                        $l->line('tax_receivable', (string) $tax),
                        $l->line($cash->id, '0', (string) $total),
                    ];
                }
            } else {
                $lines = [
                    $l->line($cash->id, $receipt ? $d['amount'] : '0', $receipt ? '0' : $d['amount']),
                    $l->line($offset->id, $receipt ? '0' : $d['amount'], $receipt ? $d['amount'] : '0'),
                ];
            }

            return $l->system($u, [
                'book' => $d['book'],
                'branch_id' => $d['branch_id'],
                'date' => $d['date'],
                'reference' => $d['reference'],
                'description' => $d['description'],
                'cashflow' => $d['kind'] === 'transfer' ? 'none' : $d['cashflow'],
                'source_type' => 'cashbook',
                'source_id' => $d['request_id'],
                'lines' => $lines,
            ]);
        }, 3);
    }

    public function correct(User $u, int $batchId, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.post');
        AccountingAccess::permit($u, 'accounting.reverse');

        return DB::transaction(function () use ($u, $batchId, $input) {
            $old = JournalBatch::where('source_type', 'cashbook')->findOrFail($batchId);
            AccountingAccess::branch($u, $old->branch_id);
            $alreadyReversed = JournalBatch::where('source_type', 'reversal')
                ->where('source_id', (string) $old->id)
                ->exists();
            if ($alreadyReversed) {
                throw ValidationException::withMessages(['cashbook' => 'This transaction was already reversed.']);
            }

            $l = app(AccountingLedger::class);
            $l->reverse($u, $old, [
                'date' => $input['date'] ?? $old->date,
                'reason' => 'Correction of '.$old->number.': '.($input['description'] ?? 'Corrected cashbook entry'),
            ]);

            $correctedInput = [
                ...$input,
                'request_id' => $input['request_id'] ?? (string) Str::uuid(),
                'book' => $old->book,
                'allow_overdraft' => true,
            ];

            return $this->post($u, $correctedInput);
        }, 3);
    }

    public function reverse(User $u, int $batchId, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.reverse');
        $old = JournalBatch::where('source_type', 'cashbook')->findOrFail($batchId);
        AccountingAccess::branch($u, $old->branch_id);

        $d = Validator::make($input, [
            'date' => 'required|date_format:Y-m-d',
            'reason' => 'required|string|max:2000',
        ])->validate();

        $alreadyReversed = JournalBatch::where('source_type', 'reversal')
            ->where('source_id', (string) $old->id)
            ->exists();
        if ($alreadyReversed) {
            throw ValidationException::withMessages(['cashbook' => 'This transaction was already reversed.']);
        }

        return app(AccountingLedger::class)->reverse($u, $old, $d);
    }

    public function transactions(User $u, array $filters = []): array
    {
        AccountingAccess::permit($u, 'accounting.view');
        $book = $filters['book'] ?? AccountingAccess::book();
        $branches = AccountingAccess::branches($u)->get()->keyBy('id');
        $branchIds = $branches->keys()->all();

        $batches = JournalBatch::where('book', $book)
            ->where('source_type', 'cashbook')
            ->whereIn('branch_id', $branchIds)
            ->with(['lines.account'])
            ->latest('id')
            ->limit(200)
            ->get();

        $reversals = JournalBatch::where('book', $book)
            ->where('source_type', 'reversal')
            ->whereIn('source_id', $batches->pluck('id')->map(fn ($id) => (string) $id))
            ->get()
            ->keyBy('source_id');

        $taxMapping = AccountMapping::whereIn('role', ['tax_receivable', 'tax_payable'])->pluck('account_id')->all();

        return $batches->map(function ($batch) use ($branches, $reversals, $taxMapping) {
            $cashLines = [];
            $offsetLines = [];
            $taxLines = [];

            foreach ($batch->lines as $line) {
                if ($line->account?->is_cash) {
                    $cashLines[] = $line;
                } elseif (in_array($line->account_id, $taxMapping, true)) {
                    $taxLines[] = $line;
                } else {
                    $offsetLines[] = $line;
                }
            }

            $kind = 'payment';
            $cashAccount = null;
            $offsetAccount = null;
            $amount = '0.00';
            $tax = '0.00';

            if (count($cashLines) >= 2) {
                $kind = 'transfer';
                $sender = collect($cashLines)->first(fn ($l) => BigDecimal::of($l->credit)->isPositive());
                $receiver = collect($cashLines)->first(fn ($l) => BigDecimal::of($l->debit)->isPositive());
                $cashAccount = $sender?->account;
                $offsetAccount = $receiver?->account;
                $amount = $sender?->credit ?? '0.00';
            } elseif (count($cashLines) === 1) {
                $cashLine = $cashLines[0];
                $cashAccount = $cashLine->account;
                $offsetAccount = $offsetLines[0]->account ?? null;
                if (BigDecimal::of($cashLine->debit)->isPositive()) {
                    $kind = 'receipt';
                    $amount = $cashLine->debit;
                } else {
                    $kind = 'payment';
                    $amount = $cashLine->credit;
                }
            }

            if (count($taxLines) > 0) {
                $taxLine = $taxLines[0];
                $tax = BigDecimal::of($taxLine->debit)->isPositive() ? $taxLine->debit : $taxLine->credit;
            }

            $reversal = $reversals->get((string) $batch->id);

            return [
                'id' => $batch->id,
                'number' => $batch->number,
                'date' => $batch->date,
                'branch_id' => $batch->branch_id,
                'branch_name' => $branches[$batch->branch_id]->name ?? 'Branch',
                'kind' => $kind,
                'cash_account_id' => $cashAccount?->id,
                'cash_account_code' => $cashAccount?->code,
                'cash_account_name' => $cashAccount?->name,
                'offset_account_id' => $offsetAccount?->id,
                'offset_account_code' => $offsetAccount?->code,
                'offset_account_name' => $offsetAccount?->name,
                'amount' => (string) BigDecimal::of($amount)->toScale(2),
                'tax' => (string) BigDecimal::of($tax)->toScale(2),
                'reference' => $batch->reference,
                'description' => $batch->description,
                'cashflow' => $batch->cashflow,
                'is_reversed' => $reversal !== null,
                'reversal_number' => $reversal?->number,
                'reversal_id' => $reversal?->id,
            ];
        })->values()->all();
    }
}

<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\JournalBatch;
use App\Models\JournalLine;
use App\Models\PostingLink;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingLedger
{
    public const AMOUNT = 'regex:/^\d{1,18}(\.\d{1,4})?$/';

    public static function value(string $v): string
    {
        return (string) BigDecimal::of($v)->toScale(4);
    }

    public static function today(): string
    {
        return CarbonImmutable::now(config('pos.report_timezone'))->toDateString();
    }

    public function account(string $role): ChartAccount
    {
        $m = AccountMapping::where('role', $role)->first();
        if (! $m) {
            throw ValidationException::withMessages(['mapping' => 'Missing account mapping: '.$role]);
        }$a = ChartAccount::findOrFail($m->account_id);
        if (! $a->active) {
            throw ValidationException::withMessages(['mapping' => 'Mapped account is inactive: '.$role]);
        }

        return $a;
    }

    public function line(string|int $account, string $debit = '0', string $credit = '0', ?string $description = null): array
    {
        return ['account_id' => is_int($account) ? $account : $this->account($account)->id, 'debit' => self::value($debit), 'credit' => self::value($credit), 'description' => $description];
    }

    public function balance(int $account, string $book, ?int $branch = null, ?string $through = null, ?string $before = null): string
    {
        $q = JournalLine::where('account_id', $account)->whereHas('batch', function ($q) use ($book, $branch, $through, $before) {
            $q->where('status', 'posted')->where('book', $book);
            if ($branch) {
                $q->where('branch_id', $branch);
            }if ($through) {
                $q->where('date', '<=', $through);
            }if ($before) {
                $q->where('date', '<', $before);
            }
        });
        $n = BigDecimal::zero();
        foreach ($q->lazyById(500) as $l) {
            $n = $n->plus($l->debit)->minus($l->credit);
        }

        return (string) $n->toScale(4);
    }

    public function manual(User $u, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.post');
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'book' => ['required', Rule::in(['training', 'live'])], 'branch_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:160', 'description' => 'required|string|max:2000', 'cashflow' => ['required', Rule::in(['none', 'operating', 'investing', 'financing'])], 'opening' => 'sometimes|boolean', 'lines' => 'required|array|min:2|max:200', 'lines.*.account_id' => 'required|integer', 'lines.*.debit' => ['required', 'string', self::AMOUNT], 'lines.*.credit' => ['required', 'string', self::AMOUNT], 'lines.*.description' => 'nullable|string|max:255'])->validate();
        $controlled = AccountMapping::whereIn('role', ['inventory', 'cash', 'inventory_transit', 'branch_clearing'])->pluck('account_id');
        foreach ($d['lines'] as $l) {
            if ($controlled->contains($l['account_id'])) {
                throw ValidationException::withMessages(['lines' => 'Inventory and drawer control accounts require their business workflows.']);
            }
        }

        return $this->post($u, ['source_type' => ($d['opening'] ?? false) ? 'opening_financial' : 'manual', 'source_id' => $d['request_id'], ...collect($d)->except('request_id', 'opening')->all()], false) ?? throw ValidationException::withMessages(['lines' => 'A journal needs positive balanced entries.']);
    }

    public function system(User $u, array $data): ?JournalBatch
    {
        return $this->post($u, $data, true);
    }

    private function post(User $u, array $data, bool $system): ?JournalBatch
    {
        return DB::transaction(function () use ($u, $data, $system) {
            AccountingAccess::lock($u, $system ? null : 'accounting.post');
            $setting = AccountingAccess::setting();
            AccountingAccess::branch($u, (int) $data['branch_id'], $data['source_type'] !== 'opening_inventory');
            if (! in_array($data['book'], ['training', 'live'], true) || ($data['book'] === 'live' && ! $setting->live_enabled)) {
                throw ValidationException::withMessages(['book' => 'Live accounting has not been activated.']);
            }
            $lines = [];
            foreach ($data['lines'] as $l) {
                $debit = BigDecimal::of((string) $l['debit']);
                $credit = BigDecimal::of((string) $l['credit']);
                if ($debit->isZero() && $credit->isZero()) {
                    continue;
                }if ($debit->isNegative() || $credit->isNegative() || ($debit->isPositive() === $credit->isPositive())) {
                    throw ValidationException::withMessages(['lines' => 'Each journal line must have exactly one positive debit or credit.']);
                }$lines[] = ['account_id' => (int) $l['account_id'], 'debit' => (string) $debit->toScale(4), 'credit' => (string) $credit->toScale(4), 'description' => $l['description'] ?? null];
            }
            $canonical = [...collect($data)->except('lines')->all(), 'lines' => $lines, 'actor_id' => $u->id, 'currency' => $setting->currency];
            $hash = PosMath::hash($canonical);
            $key = ['book' => $data['book'], 'source_type' => $data['source_type'], 'source_id' => (string) $data['source_id']];
            $existing = PostingLink::where($key)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Financial source was already posted with different data.']);
                }

                return $existing->journal_id ? JournalBatch::with('lines.account')->findOrFail($existing->journal_id) : null;
            }
            if (! $lines) {
                if (! $system) {
                    throw ValidationException::withMessages(['lines' => 'Zero-value journals cannot be posted.']);
                }PostingLink::create([...$key, 'payload_hash' => $hash, 'journal_id' => null, 'created_at' => now()]);

                return null;
            }
            $debits = PosMath::sum(array_column($lines, 'debit'), 4);
            $credits = PosMath::sum(array_column($lines, 'credit'), 4);
            if (count($lines) < 2 || $debits !== $credits) {
                throw ValidationException::withMessages(['lines' => 'Journal debits and credits must balance exactly.']);
            }
            $period = AccountingPeriod::where('starts_on', '<=', $data['date'])->where('ends_on', '>=', $data['date'])->first();
            if (! $period || $period->locked) {
                throw ValidationException::withMessages(['date' => 'No open accounting period covers this date.']);
            }
            if ($setting->currency !== Company::firstOrFail()->base_currency) {
                throw ValidationException::withMessages(['currency' => 'Company and ledger currencies differ.']);
            }
            $cashNet = BigDecimal::zero();
            $tradeControls = [];
            foreach (['purchasing' => ['payable', 'receipt_accrual'], 'sales' => ['receivable']] as $module => $roles) {
                if (TenantModule::where('key', $module)->where('enabled', true)->exists()) {
                    $tradeControls = [...$tradeControls, ...AccountMapping::whereIn('role', $roles)->pluck('account_id')->all()];
                }
            }
            foreach ($lines as $l) {
                if (in_array($l['account_id'], $tradeControls, true) && ! str_starts_with($data['source_type'], 'trade_')) {
                    throw ValidationException::withMessages(['lines' => 'Use supplier bills, credit invoices and their settlements for party control accounts.']);
                }
                $a = ChartAccount::findOrFail($l['account_id']);
                if (! $a->active) {
                    throw ValidationException::withMessages(['lines' => 'Inactive accounts cannot receive new postings.']);
                }if (\App\Models\ForeignAccount::where('account_id', $a->id)->exists() && !in_array($data['source_type'], ['fx_ledger','fx_revalue','trade_fx_settlement'], true)) { throw ValidationException::withMessages(['lines'=>'Foreign accounts require their currency workflow.']); }
                if ($a->is_cash) {
                    try {
                        if (!\App\Models\ForeignAccount::where('account_id', $a->id)->exists()) BigDecimal::of($l['debit'])->toScale(2);
                        if (!\App\Models\ForeignAccount::where('account_id', $a->id)->exists()) BigDecimal::of($l['credit'])->toScale(2);
                    } catch (RoundingNecessaryException $e) {
                        throw ValidationException::withMessages(['lines' => 'Cash and bank amounts must use two decimal places.']);
                    }if ($data['source_type'] !== 'fx_revalue') $cashNet = $cashNet->plus($l['debit'])->minus($l['credit']);
                }
                $bank = BankAccount::where('account_id', $a->id)->first();
                if ($bank) {
                    if ($bank->branch_id && $bank->branch_id !== (int) $data['branch_id']) {
                        throw ValidationException::withMessages(['branch_id' => 'This bank account belongs to another branch.']);
                    }if (BankReconciliation::where('bank_account_id', $bank->id)->where('book', $data['book'])->where('status', 'closed')->where('ends_on', '>=', $data['date'])->exists()) {
                        throw ValidationException::withMessages(['date' => 'Bank history is reconciled through this date. Post a current-period correction instead.']);
                    }
                }
            }
            if (! in_array($data['cashflow'], ['none', 'operating', 'investing', 'financing'], true) || (! $cashNet->isZero() && $data['cashflow'] === 'none')) {
                throw ValidationException::withMessages(['cashflow' => 'Cash movements require a cash-flow classification.']);
            }
            $sequence = (JournalBatch::max('sequence') ?? 0) + 1;
            $batch = JournalBatch::create([...collect($data)->except('lines')->all(), 'period_id' => $period->id, 'actor_id' => $u->id, 'sequence' => $sequence, 'number' => 'JRN-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT), 'currency' => $setting->currency, 'payload_hash' => $hash, 'created_at' => now(), 'status' => 'draft']);
            foreach ($lines as $l) {
                JournalLine::create([...$l, 'batch_id' => $batch->id]);
            }$batch->update(['status' => 'posted', 'posted_at' => now()]);
            PostingLink::create([...$key, 'payload_hash' => $hash, 'journal_id' => $batch->id, 'created_at' => now()]);
            Audit::record('accounting.journal_posted', (string) $batch->id, ['book' => $batch->book, 'source_type' => $batch->source_type, 'debits' => $debits, 'credits' => $credits]);

            return $batch->load('lines.account');
        }, 3);
    }

    public function reverse(User $u, JournalBatch $original, array $input): JournalBatch
    {
        AccountingAccess::permit($u, 'accounting.reverse');
        $d = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000'])->validate();
        AccountingAccess::branch($u, $original->branch_id);
        if (! in_array($original->source_type, ['manual', 'opening_financial', 'cashbook'], true)) {
            throw ValidationException::withMessages(['journal' => 'Use the original business workflow to reverse this source.']);
        }

        return DB::transaction(function () use ($u, $original, $d) {
            AccountingAccess::lock($u, 'accounting.reverse');
            $lines = $original->lines()->get()->map(fn ($l) => ['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'description' => 'Reversal of '.$original->number])->all();

            return $this->system($u, ['book' => $original->book, 'source_type' => 'reversal', 'source_id' => (string) $original->id, 'branch_id' => $original->branch_id, 'date' => $d['date'], 'reference' => 'REV-'.$original->number, 'description' => $d['reason'], 'cashflow' => $original->cashflow, 'lines' => $lines]);
        });
    }
}

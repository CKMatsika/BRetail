<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountingSetting;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\JournalBatch;
use App\Models\JournalLine;
use App\Models\PosHold;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountingSetup
{
    public function initialize(User $u, array $input): AccountingSetting
    {
        AccountingAccess::permit($u, 'accounting.configure');
        abort_unless($u->role->company_wide, 403);
        $d = Validator::make($input, ['name' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on'])->validate();

        return DB::transaction(function () use ($u, $d) {
            AccountingAccess::lock($u, 'accounting.configure');
            if ($existing = AccountingSetting::first()) {
                return $existing;
            }if (PosSession::where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['setup' => 'Close all cashier shifts before initializing accounting.']);
            }$today = AccountingLedger::today();
            if ($today < $d['starts_on'] || $today > $d['ends_on']) {
                throw ValidationException::withMessages(['starts_on' => 'The initial period must include today.']);
            }
            $s = AccountingSetting::create(['currency' => Company::firstOrFail()->base_currency, 'start_date' => $d['starts_on'], 'configured_at' => now()]);
            $period = AccountingPeriod::create($d);
            foreach (config('accounting.accounts') as [$code,$name,$type,$cash,$role]) {
                $a = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'report_group' => $type, 'is_cash' => $cash]);
                if ($role) {
                    AccountMapping::create(['role' => $role, 'account_id' => $a->id]);
                }
            }
            BankAccount::create(['name' => 'Main bank', 'account_id' => app(AccountingLedger::class)->account('bank')->id, 'currency' => $s->currency]);
            $this->openingInventory($u, 'training', 'setup-'.$s->id);
            Audit::record('accounting.initialized', (string) $s->id);

            return $s;
        });
    }

    public function inventoryValues(): array
    {
        $warehouses = Warehouse::get()->keyBy('id');
        $totals = [];
        foreach (StockBalance::get() as $b) {
            $branch = $warehouses[$b->warehouse_id]->branch_id;
            $totals[$branch] ??= BigDecimal::zero();
            $totals[$branch] = $totals[$branch]->plus($b->value);
        }

        return array_map(fn ($v) => (string) $v->toScale(4), $totals);
    }

    public function openingInventory(User $u, string $book, string $key): array
    {
        $values = $this->inventoryValues();
        $ledger = app(AccountingLedger::class);
        foreach ($values as $branch => $value) {
            if (BigDecimal::of($value)->isPositive()) {
                $ledger->system($u, ['book' => $book, 'branch_id' => (int) $branch, 'date' => AccountingLedger::today(), 'source_type' => 'opening_inventory', 'source_id' => $key.'-'.$branch, 'reference' => 'OPENING-INVENTORY', 'description' => 'Verified inventory opening snapshot', 'cashflow' => 'none', 'lines' => [$ledger->line('inventory', $value), $ledger->line('opening_equity', '0', $value)]]);
            }
        }

        return $values;
    }

    public function activate(User $u, array $input): AccountingSetting
    {
        AccountingAccess::permit($u, 'accounting.configure');
        abort_unless($u->role->company_wide, 403);
        Validator::make($input, ['opening_stock_confirmed' => 'required|accepted', 'base_currency_precision_confirmed' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($u) {
            AccountingAccess::lock($u, 'accounting.configure');
            $s = AccountingAccess::setting();
            if ($s->live_enabled) {
                return $s;
            }
            if (\App\Models\ForeignEntry::where('book','training')->exists() || \App\Models\BranchTransfer::where('book','training')->whereNotIn('status',['cancelled'])->exists()) { throw ValidationException::withMessages(['live'=>'Training currency or transfer history exists. Use a fresh company for live business.']); }
            if (TradeDocument::where('book', 'training')->exists() || PurchaseOrder::where('book', 'training')->whereNotIn('status', ['cancelled'])->exists()) {
                throw ValidationException::withMessages(['live' => 'Training back-office documents exist. Use a fresh company for live business.']);
            }
            if (! TenantModule::where('key', 'inventory')->where('enabled', true)->exists()) {
                throw ValidationException::withMessages(['live' => 'Enable inventory before live retail activation.']);
            }if (PosSale::where('mode', 'training')->exists()) {
                throw ValidationException::withMessages(['live' => 'This company contains training sales. Create a fresh company for live business; training records will not be converted.']);
            }if (PosSession::where('status', 'open')->exists() || PosHold::where('status', 'held')->exists() || StockCount::where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['live' => 'Close shifts and resolve held carts and draft counts first.']);
            }
            $period = AccountingPeriod::where('starts_on', '<=', AccountingLedger::today())->where('ends_on', '>=', AccountingLedger::today())->where('locked', false)->first();
            if (! $period) {
                throw ValidationException::withMessages(['live' => 'An open period must cover today.']);
            }foreach (AccountMapping::get() as $m) {
                if (! ChartAccount::findOrFail($m->account_id)->active) {
                    throw ValidationException::withMessages(['mapping' => 'Activate every mapped account first.']);
                }
            }
            $s->update(['live_enabled' => true, 'live_activated_at' => now(), 'cutover_stock' => $this->inventoryValues()]);
            $this->openingInventory($u, 'live', 'live-'.$s->id);
            Audit::record('accounting.live_activated', (string) $s->id, ['currency' => $s->currency]);

            return $s;
        }, 3);
    }

    public function period(User $u, array $input): AccountingPeriod
    {
        AccountingAccess::permit($u, 'accounting.periods');
        $d = Validator::make($input, ['name' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on'])->validate();

        return DB::transaction(function () use ($u, $d) {
            AccountingAccess::lock($u, 'accounting.periods');
            if (AccountingPeriod::where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Accounting periods cannot overlap.']);
            }$p = AccountingPeriod::create($d);
            Audit::record('accounting.period_created', (string) $p->id);

            return $p;
        });
    }

    public function lockPeriod(User $u, AccountingPeriod $period): AccountingPeriod
    {
        AccountingAccess::permit($u, 'accounting.periods');

        return DB::transaction(function () use ($u, $period) {
            AccountingAccess::lock($u, 'accounting.periods');
            $period->refresh();
            if (! $period->locked) {
                $period->update(['locked' => true, 'locked_at' => now(), 'locked_by' => $u->id]);
                Audit::record('accounting.period_locked', (string) $period->id);
            }

            return $period;
        });
    }

    public function account(User $u, array $input, ?ChartAccount $account = null): ChartAccount
    {
        AccountingAccess::permit($u, 'accounting.configure');
        $d = Validator::make($input, ['code' => ['required', 'alpha_dash', 'max:20', Rule::unique('chart_accounts')->where('tenant_id', $u->tenant_id)->ignore($account?->id)], 'name' => 'required|string|max:160', 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])], 'report_group' => 'required|string|max:100', 'is_cash' => 'required|boolean', 'active' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($u, $d, $account) {
            AccountingAccess::lock($u, 'accounting.configure');
            if ($account) {
                if (JournalLine::where('account_id', $account->id)->exists() && ($account->type !== $d['type'] || $account->is_cash !== $d['is_cash'])) {
                    throw ValidationException::withMessages(['type' => 'Account type and cash classification are fixed after use.']);
                }if (AccountMapping::where('account_id', $account->id)->exists() && (! $d['active'] || $account->type !== $d['type'] || $account->is_cash !== $d['is_cash'])) {
                    throw ValidationException::withMessages(['active' => 'Mapped account classification must remain active and stable.']);
                }
            }$a = $account ?? new ChartAccount;
            $a->fill($d);
            $a->save();
            Audit::record('accounting.account_saved', (string) $a->id);

            return $a;
        });
    }

    public function mapping(User $u, array $input): void
    {
        AccountingAccess::permit($u, 'accounting.configure');
        $d = Validator::make($input, ['role' => 'required|string|max:40', 'account_id' => 'required|integer'])->validate();
        DB::transaction(function () use ($u, $d) {
            AccountingAccess::lock($u, 'accounting.configure');
            if (JournalBatch::where('status', 'posted')->exists()) {
                throw ValidationException::withMessages(['mapping' => 'Mappings are fixed after the first journal. Rename account codes/names without changing their identity instead.']);
            }$mapping = AccountMapping::where('role', $d['role'])->firstOrFail();
            $old = ChartAccount::findOrFail($mapping->account_id);
            $new = ChartAccount::findOrFail($d['account_id']);
            if (! $new->active || $new->type !== $old->type || $new->is_cash !== $old->is_cash) {
                throw ValidationException::withMessages(['account_id' => 'Choose an active account with the same type and cash classification.']);
            }$mapping->update(['account_id' => $new->id]);
            Audit::record('accounting.mapping_changed', $d['role']);
        });
    }
}

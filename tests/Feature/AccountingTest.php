<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AccountingSetting;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\JournalBatch;
use App\Models\JournalLine;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingBanking;
use App\Services\AccountingCashbook;
use App\Services\AccountingLedger;
use App\Services\AccountingPurchases;
use App\Services\AccountingReports;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\PosCart;
use App\Services\PosReturns;
use App\Services\PosSales;
use App\Services\PosShifts;
use App\Services\StockLedger;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->owner('finance@example.test');
        $this->warehouse = Warehouse::firstOrFail();
        $this->setupBooks();
    }

    private function owner(string $email): User
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => $email, 'password' => 'SecurePassword123', 'company_name' => 'Finance shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main warehouse', 'tax_rate' => '15']);
        TenantModule::whereIn('key', ['inventory', 'pos', 'accounting'])->update(['enabled' => true]);
        DB::table('tenant_subscriptions')->where('tenant_id', $u->tenant_id)->update(['status' => 'active']);

        return $u;
    }

    private function setupBooks(): void
    {
        $year = substr(AccountingLedger::today(), 0, 4);
        app(AccountingSetup::class)->initialize($this->owner, ['name' => 'FY', 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31']);
    }

    private function account(string $role): int
    {
        return app(AccountingLedger::class)->account($role)->id;
    }

    private function journal(array $overrides = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'book' => 'training', 'branch_id' => $this->warehouse->branch_id, 'date' => AccountingLedger::today(), 'reference' => 'CAPITAL', 'description' => 'Verified opening capital', 'cashflow' => 'financing', 'lines' => [['account_id' => $this->account('cash_safe'), 'debit' => '100', 'credit' => '0'], ['account_id' => $this->account('opening_equity'), 'debit' => '0', 'credit' => '100']]], ...$overrides];
    }

    private function balance(string $role, string $book = 'training'): string
    {
        return app(AccountingLedger::class)->balance($this->account($role), $book, $this->warehouse->branch_id);
    }

    private function invalid(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function product(): ProductVariant
    {
        $p = app(Catalog::class)->product($this->owner, ['name' => 'Test product', 'unit' => 'each', 'tax_class' => 'standard', 'active' => true, 'attributes' => [], 'warranty_months' => 0]);

        return app(Catalog::class)->variant($this->owner, $p, ['name' => 'Default', 'sku' => 'TEST', 'cost_price' => '3.3333', 'selling_price' => '10', 'wholesale_price' => '10', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
    }

    public function test_balanced_journal_retry_reversal_and_locked_period(): void
    {
        $l = app(AccountingLedger::class);
        $d = $this->journal();
        $j = $l->manual($this->owner, $d);
        $this->assertSame($j->id, $l->manual($this->owner, $d)->id);
        $this->assertSame('100.0000', $this->balance('cash_safe'));
        $this->invalid(fn () => $l->manual($this->owner, [...$d, 'reference' => 'Changed']));
        $r = $l->reverse($this->owner, $j, ['date' => AccountingLedger::today(), 'reason' => 'Correct opening']);
        $this->assertSame('0.0000', $this->balance('cash_safe'));
        $this->assertSame($r->id, $l->reverse($this->owner, $j, ['date' => AccountingLedger::today(), 'reason' => 'Correct opening'])->id);
        app(AccountingSetup::class)->lockPeriod($this->owner, AccountingPeriod::first());
        $this->invalid(fn () => $l->manual($this->owner, $this->journal()));
        $this->assertSame(2, JournalBatch::count());
    }

    public function test_rejects_unbalanced_cash_precision_and_control_account_manual_entries(): void
    {
        $l = app(AccountingLedger::class);
        $d = $this->journal();
        $d['lines'][1]['credit'] = '99';
        $this->invalid(fn () => $l->manual($this->owner, $d));
        $d = $this->journal();
        $d['lines'][0]['debit'] = '0.0001';
        $d['lines'][1]['credit'] = '0.0001';
        $this->invalid(fn () => $l->manual($this->owner, $d));
        $d = $this->journal();
        $d['lines'][0]['account_id'] = $this->account('inventory');
        $this->invalid(fn () => $l->manual($this->owner, $d));
        $this->assertSame(0, JournalBatch::count());
    }

    public function test_cashbook_funding_transfer_payment_and_exact_reports(): void
    {
        $l = app(AccountingLedger::class);
        $l->manual($this->owner, $this->journal());
        $c = app(AccountingCashbook::class);
        $d = ['request_id' => (string) Str::uuid(), 'book' => 'training', 'branch_id' => $this->warehouse->branch_id, 'date' => AccountingLedger::today(), 'reference' => 'DEPOSIT', 'description' => 'Deposit safe funds', 'kind' => 'transfer', 'cash_account_id' => $this->account('cash_safe'), 'offset_account_id' => $this->account('bank'), 'amount' => '60', 'cashflow' => 'none'];
        $j = $c->post($this->owner, $d);
        $this->assertSame($j->id, $c->post($this->owner, $d)->id);
        $this->assertSame('40.0000', $this->balance('cash_safe'));
        $this->assertSame('60.0000', $this->balance('bank'));
        $c->post($this->owner, [...$d, 'request_id' => (string) Str::uuid(), 'kind' => 'payment', 'cash_account_id' => $this->account('bank'), 'offset_account_id' => $this->account('expense'), 'amount' => '10', 'cashflow' => 'operating']);
        $this->invalid(fn () => $c->post($this->owner, [...$d, 'request_id' => (string) Str::uuid(), 'amount' => '1000']));
        $r = app(AccountingReports::class)->run($this->owner, ['book' => 'training', 'from' => substr(AccountingLedger::today(), 0, 4).'-01-01', 'to' => AccountingLedger::today()]);
        $this->assertSame('-10.0000', $r['profit_loss']['profit']);
        $this->assertSame('0.0000', $r['balance_sheet']['difference']);
        $this->assertSame('90.0000', $r['cash_flow']['closing']);
        $this->assertSame('100.0000', $r['cash_flow']['categories']['financing']);
        $this->assertSame('-10.0000', $r['cash_flow']['categories']['operating']);
    }

    public function test_purchase_posts_exact_stock_input_tax_payable_and_retry(): void
    {
        $v = $this->product();
        $d = ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'supplier_name' => 'Supplier', 'invoice_number' => 'SUP-1', 'date' => AccountingLedger::today(), 'settlement' => 'payable', 'tax_rate' => '15', 'items' => [['variant_id' => $v->id, 'quantity' => '1.001', 'unit_cost' => '3.3333']]];
        $p = app(AccountingPurchases::class)->post($this->owner, $d);
        $this->assertSame($p->id, app(AccountingPurchases::class)->post($this->owner, $d)->id);
        $this->assertSame('3.3366', $this->balance('inventory'));
        $this->assertSame('0.5000', $this->balance('tax_receivable'));
        $this->assertSame('-3.8400', $this->balance('payable'));
        $this->assertSame('3.3366', StockBalance::first()->value);
        $this->assertSame('0.0034', $this->balance('rounding'));
        $this->invalid(fn () => app(AccountingPurchases::class)->post($this->owner, [...$d, 'request_id' => (string) Str::uuid()]));
    }

    public function test_live_pos_posts_cost_returns_float_variance_atomically(): void
    {
        $v = $this->product();
        app(StockLedger::class)->post($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Verified stock', 'lines' => [['variant_id' => $v->id, 'quantity' => '10', 'unit_cost' => '3.3333']]]);
        app(AccountingSetup::class)->activate($this->owner, ['opening_stock_confirmed' => true, 'base_currency_precision_confirmed' => true]);
        $l = app(AccountingLedger::class);
        $l->manual($this->owner, $this->journal(['book' => 'live']));
        $s = app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '20']);
        $this->assertSame('live', $s->mode);
        $cart = ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $v->id, 'quantity' => '1', 'discount_percent' => '0']]];
        $quote = app(PosCart::class)->quote($this->owner, $cart);
        $payload = [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'quote_token' => $quote['quote_token'], 'payments' => [['method' => 'cash', 'amount' => '15']]];
        $sale = app(PosSales::class)->checkout($this->owner, $payload);
        $this->assertSame($sale->id, app(PosSales::class)->checkout($this->owner, $payload)->id);
        $this->assertSame('31.5000', $this->balance('cash', 'live'));
        $this->assertSame('3.3333', $this->balance('cogs', 'live'));
        $this->assertSame('-10.0000', $this->balance('sales', 'live'));
        $items = [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']];
        $rq = app(PosReturns::class)->quote($this->owner, $sale, ['lines' => $items]);
        app(PosReturns::class)->post($this->owner, $sale, ['request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'quote_token' => $rq['quote_token'], 'reason' => 'Returned item', 'lines' => $items, 'payments' => [['method' => 'cash', 'amount' => '11.50']]]);
        $this->assertSame('33.3330', $this->balance('inventory', 'live'));
        $this->assertSame('0.0000', $this->balance('cogs', 'live'));
        app(PosShifts::class)->close($this->owner, $s->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '19.50']);
        $this->assertSame('0.0000', $this->balance('cash', 'live'));
        $this->assertSame('99.5000', $this->balance('cash_safe', 'live'));
        $this->assertSame('0.5000', $this->balance('cash_short', 'live'));
        $r = app(AccountingReports::class)->run($this->owner, ['book' => 'live', 'from' => substr(AccountingLedger::today(), 0, 4).'-01-01', 'to' => AccountingLedger::today()]);
        $this->assertSame('0.0000', $r['balance_sheet']['difference']);
        $this->assertSame('0.0000', $r['inventory_reconciliation']['difference']);
    }

    public function test_locked_period_rolls_back_pos_and_stock(): void
    {
        $v = $this->product();
        app(StockLedger::class)->post($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Verified stock', 'lines' => [['variant_id' => $v->id, 'quantity' => '2', 'unit_cost' => '3']]]);
        $s = app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '0']);
        $cart = ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $v->id, 'quantity' => '1', 'discount_percent' => '0']]];
        $q = app(PosCart::class)->quote($this->owner, $cart);
        app(AccountingSetup::class)->lockPeriod($this->owner, AccountingPeriod::first());
        $this->invalid(fn () => app(PosSales::class)->checkout($this->owner, [...$cart, 'request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'quote_token' => $q['quote_token'], 'payments' => [['method' => 'cash', 'amount' => '11.50']]]));
        $this->assertSame(0, PosSale::count());
        $this->assertSame('2.000', StockBalance::first()->quantity);
    }

    public function test_bank_statement_group_matching_outstanding_and_cutoff(): void
    {
        $l = app(AccountingLedger::class);
        $j = $l->manual($this->owner, $this->journal(['lines' => [['account_id' => $this->account('bank'), 'debit' => '40', 'credit' => '0'], ['account_id' => $this->account('bank'), 'debit' => '60', 'credit' => '0'], ['account_id' => $this->account('opening_equity'), 'debit' => '0', 'credit' => '100']]]));
        $fee = $l->manual($this->owner, $this->journal(['reference' => 'FEE', 'cashflow' => 'operating', 'lines' => [['account_id' => $this->account('bank_fees'), 'debit' => '10', 'credit' => '0'], ['account_id' => $this->account('bank'), 'debit' => '0', 'credit' => '10']]]));
        $b = app(AccountingBanking::class);
        $r = $b->start($this->owner, ['request_id' => (string) Str::uuid(), 'bank_account_id' => BankAccount::first()->id, 'book' => 'training', 'starts_on' => AccountingLedger::today(), 'ends_on' => AccountingLedger::today(), 'opening_balance' => '0', 'closing_balance' => '100', 'baseline_confirmed' => true, 'csv' => 'date,reference,description,amount'."\n".AccountingLedger::today().',CAP,Deposit,100']);
        $this->invalid(fn () => $b->finish($this->owner, $r));
        $b->match($this->owner, $r, ['statement_line_id' => $r->lines->first()->id, 'journal_line_ids' => $j->lines->where('account_id', $this->account('bank'))->pluck('id')->all()]);
        $closed = $b->finish($this->owner, $r);
        $this->assertSame('-10.0000', $closed->outstanding);
        $this->assertSame('90.0000', $closed->ledger_balance);
        $this->invalid(fn () => $l->reverse($this->owner, $fee, ['date' => AccountingLedger::today(), 'reason' => 'Backdated correction']));
        $this->assertSame(2, JournalBatch::count());
    }

    public function test_cashier_cannot_read_accounting_and_tenants_are_isolated(): void
    {
        $cashier = new User(['name' => 'Cashier', 'email' => 'cashier@example.test', 'password' => 'SecurePassword123']);
        $cashier->tenant_id = $this->owner->tenant_id;
        $cashier->role_id = Role::where('name', 'Cashier')->first()->id;
        $cashier->save();
        $this->actingAs($cashier)->getJson('/accounting/data')->assertForbidden();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $l = app(AccountingLedger::class);
        $j = $l->manual($this->owner, $this->journal());
        $other = $this->owner('other@example.test');
        $this->actingAs($other)->postJson('/accounting/reverse/'.$j->id, ['date' => AccountingLedger::today(), 'reason' => 'No access'])->assertNotFound();
        app(TenantContext::class)->id = $other->tenant_id;
        $this->assertSame(0, JournalBatch::count());
    }

    public function test_postgresql_rejects_ledger_tampering(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL guards');
        }$j = app(AccountingLedger::class)->manual($this->owner, $this->journal());
        foreach ([fn () => DB::table('journal_lines')->where('batch_id', $j->id)->update(['debit' => '9']), fn () => DB::table('journal_lines')->insert(['tenant_id' => $this->owner->tenant_id, 'batch_id' => $j->id, 'account_id' => $this->account('cash_safe'), 'debit' => '1', 'credit' => '0']), fn () => DB::table('journal_batches')->where('id', $j->id)->update(['reference' => 'tampered'])] as $f) {
            $failed = false;
            try {
                DB::transaction($f);
            } catch (QueryException) {
                $failed = true;
            }$this->assertTrue($failed);
        }
    }

    public function test_training_sales_cannot_be_converted_to_live(): void
    {
        $v = $this->product();
        app(StockLedger::class)->post($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Training stock', 'lines' => [['variant_id' => $v->id, 'quantity' => '2', 'unit_cost' => '3']]]);
        $s = app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '0']);
        $cart = ['warehouse_id' => $this->warehouse->id, 'lines' => [['variant_id' => $v->id, 'quantity' => '1', 'discount_percent' => '0']]];
        $quote = app(PosCart::class)->quote($this->owner, $cart);
        app(PosSales::class)->checkout($this->owner, [...$cart, 'session_id' => $s->id, 'request_id' => (string) Str::uuid(), 'quote_token' => $quote['quote_token'], 'payments' => [['method' => 'bank_transfer', 'amount' => '11.50', 'reference' => 'BANK-TRAIN']]]);
        app(PosShifts::class)->close($this->owner, $s->id, ['request_id' => (string) Str::uuid(), 'counted_cash' => '0']);
        $this->invalid(fn () => app(AccountingSetup::class)->activate($this->owner, ['opening_stock_confirmed' => true, 'base_currency_precision_confirmed' => true]));
        $this->assertFalse(AccountingSetting::first()->live_enabled);
        $this->assertSame('11.5000', $this->balance('bank'));
        $this->assertSame(0, JournalBatch::where('book', 'live')->count());
    }

    public function test_accounting_api_exports_and_branch_report_isolation(): void
    {
        app(AccountingLedger::class)->manual($this->owner, $this->journal(['description' => '=HYPERLINK("unsafe")']));
        $this->actingAs($this->owner)->getJson('/api/v1/accounting/data')->assertOk()->assertJsonCount(1, 'data.journals');
        $date = AccountingLedger::today();
        $this->get('/accounting/export?book=training&from='.$date.'&to='.$date.'&report=general_ledger')->assertOk()->assertDownload('accounting-general_ledger.csv');
        app(TenantContext::class)->id = $this->owner->tenant_id;
        $branch = Branch::create(['name' => 'Other', 'code' => 'OTHER']);
        $manager = new User(['name' => 'Branch manager', 'email' => 'manager@example.test', 'password' => 'SecurePassword123']);
        $manager->tenant_id = $this->owner->tenant_id;
        $manager->role_id = Role::where('name', 'Branch Manager')->first()->id;
        $manager->save();
        $manager->branches()->attach($branch->id, ['tenant_id' => $manager->tenant_id]);
        $r = app(AccountingReports::class)->run($manager, ['book' => 'training', 'from' => $date, 'to' => $date]);
        $this->assertSame('0.0000', $r['cash_flow']['closing']);
        $this->actingAs($manager)->getJson('/accounting/reports?book=training&from='.$date.'&to='.$date.'&branch_id='.$this->warehouse->branch_id)->assertNotFound();
        app(TenantContext::class)->id = $this->owner->tenant_id;
        TenantModule::where('key', 'accounting')->update(['enabled' => false]);
        $this->actingAs($this->owner)->getJson('/api/v1/accounting/data')->assertForbidden();
    }

    public function test_future_funds_cannot_fund_today_drawer_or_future_bank_cutoff(): void
    {
        $tomorrow = CarbonImmutable::parse(AccountingLedger::today())->addDay()->toDateString();
        if (substr($tomorrow, 0, 4) !== substr(AccountingLedger::today(), 0, 4)) {
            app(AccountingSetup::class)->period($this->owner, ['name' => 'Next year', 'starts_on' => substr($tomorrow, 0, 4).'-01-01', 'ends_on' => substr($tomorrow, 0, 4).'-12-31']);
        }
        app(AccountingLedger::class)->manual($this->owner, $this->journal(['date' => $tomorrow]));
        $this->invalid(fn () => app(PosShifts::class)->open($this->owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id, 'opening_cash' => '10']));
        $this->invalid(fn () => app(AccountingBanking::class)->start($this->owner, ['request_id' => (string) Str::uuid(), 'bank_account_id' => BankAccount::first()->id, 'book' => 'training', 'starts_on' => AccountingLedger::today(), 'ends_on' => $tomorrow, 'opening_balance' => '0', 'closing_balance' => '0', 'baseline_confirmed' => true, 'lines' => []]));
        $this->assertSame(0, PosSession::count());
    }

    public function test_postgresql_refuses_unbalanced_draft_posting(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL guards');
        }
        $failed = false;
        try {
            DB::transaction(function () {
                $j = JournalBatch::create(['period_id' => AccountingPeriod::first()->id, 'branch_id' => $this->warehouse->branch_id, 'actor_id' => $this->owner->id, 'sequence' => 1, 'number' => 'JRN-000001', 'book' => 'training', 'currency' => 'USD', 'date' => AccountingLedger::today(), 'reference' => 'INVALID', 'description' => 'Unbalanced raw insertion', 'cashflow' => 'financing', 'source_type' => 'manual', 'source_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
                JournalLine::create(['batch_id' => $j->id, 'account_id' => $this->account('cash_safe'), 'debit' => '100', 'credit' => '0']);
                JournalLine::create(['batch_id' => $j->id, 'account_id' => $this->account('opening_equity'), 'debit' => '0', 'credit' => '99']);
                $j->update(['status' => 'posted', 'posted_at' => now()]);
            });
        } catch (QueryException) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $this->assertSame(0, JournalBatch::count());
    }

    public function test_cashbook_payment_with_tax_rate_splits_tax_and_posts_to_ledger_account(): void
    {
        $l = app(AccountingLedger::class);
        $l->manual($this->owner, $this->journal());
        $c = app(AccountingCashbook::class);
        $d = [
            'request_id' => (string) Str::uuid(),
            'book' => 'training',
            'branch_id' => $this->warehouse->branch_id,
            'date' => AccountingLedger::today(),
            'reference' => 'CASH-STEEL',
            'description' => 'Cash purchase of steel for job',
            'kind' => 'payment',
            'cash_account_id' => $this->account('cash_safe'),
            'offset_account_id' => $this->account('cogs'),
            'amount' => '100',
            'tax_rate' => '15',
            'cashflow' => 'operating',
        ];
        $j = $c->post($this->owner, $d);
        $this->assertNotNull($j);
        $this->assertSame('0.0000', $this->balance('cash_safe')); // 100 - 100 = 0
        $this->assertSame('86.9600', $this->balance('cogs'));
        $this->assertSame('13.0400', $this->balance('tax_receivable'));
    }

    public function test_accounting_purchase_direct_to_ledger_account(): void
    {
        $d = [
            'request_id' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse->id,
            'supplier_name' => 'Steel Supplier Direct',
            'invoice_number' => 'DIR-STEEL-101',
            'date' => AccountingLedger::today(),
            'settlement' => 'payable',
            'tax_rate' => '15',
            'items' => [
                [
                    'account_id' => $this->account('cogs'),
                    'description' => 'Mild steel plates',
                    'quantity' => '10',
                    'unit_cost' => '50',
                ],
            ],
        ];
        $p = app(AccountingPurchases::class)->post($this->owner, $d);
        $this->assertSame('500.0000', $p->net);
        $this->assertSame('75.0000', $p->tax);
        $this->assertSame('575.0000', $p->total);
        $this->assertSame('500.0000', $this->balance('cogs'));
        $this->assertSame('75.0000', $this->balance('tax_receivable'));
        $this->assertSame('-575.0000', $this->balance('payable'));
        $this->assertSame('0.0000', $this->balance('inventory'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingReports;
use App\Services\AccountingSetup;
use App\Services\Catalog;
use App\Services\CurrencyRates;
use App\Services\ForeignLedger;
use App\Services\Onboarding;
use App\Services\PurchaseOrders;
use App\Services\Suppliers;
use App\Services\TenantContext;
use App\Services\TradeCurrency;
use App\Services\TradeLedger;
use App\Services\TradePurchasing;
use App\Services\TradeSales;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TradeTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private Warehouse $w;

    private Supplier $supplier;

    private ProductVariant $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'trade@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Trade shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'purchasing', 'sales', 'backoffice'])->update(['enabled' => true]);
        $this->w = Warehouse::first();
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($this->u, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $this->supplier = app(Suppliers::class)->save($this->u, ['code' => 'SUP', 'name' => 'Supplier', 'terms_days' => 30, 'active' => true]);
        $p = app(Catalog::class)->product($this->u, ['name' => 'Product', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $this->v = app(Catalog::class)->variant($this->u, $p, ['name' => 'Standard', 'sku' => 'TRADE', 'cost_price' => '3.3333', 'selling_price' => '10', 'wholesale_price' => '10', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
    }

    private function request(array $data = []): array
    {
        return ['request_id' => (string) Str::uuid(), 'date' => AccountingLedger::today(), 'reference' => 'REF-'.Str::random(8), ...$data];
    }

    private function order(string $qty = '10'): PurchaseOrder
    {
        $o = app(PurchaseOrders::class)->create($this->u, $this->request(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->w->id, 'items' => [['variant_id' => $this->v->id, 'quantity' => $qty, 'unit_cost' => '3.3333']]]));

        return app(PurchaseOrders::class)->transition($this->u, $o->id, 'approve');
    }

    private function receive(PurchaseOrder $o, string $qty): TradeDocument
    {
        return app(TradePurchasing::class)->receive($this->u, $o->id, $this->request(['items' => [['variant_id' => $this->v->id, 'quantity' => $qty]]]));
    }

    private function invalid(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function balance(string $role): string
    {
        $l = app(AccountingLedger::class);

        return $l->balance($l->account($role)->id, 'training', $this->w->branch_id);
    }

    private function quote(string $qty = '1'): TradeDocument
    {
        $c = PosCustomer::firstOrCreate(['name' => 'Customer']);
        app(TradeLedger::class)->credit($this->u, ['customer_id' => $c->id, 'credit_limit' => '100', 'terms_days' => 30, 'active' => true]);

        return app(TradeSales::class)->quote($this->u, $this->request(['warehouse_id' => $this->w->id, 'customer_id' => $c->id, 'due_on' => AccountingLedger::today(), 'items' => [['variant_id' => $this->v->id, 'quantity' => $qty, 'unit_price' => '10', 'discount_percent' => '0']]]));
    }

    public function test_partial_receipts_bills_and_duplicate_prevention(): void
    {
        $o = $this->order();
        $r = $this->receive($o, '4');
        $this->assertSame('partially_received', $o->fresh()->status);
        $this->assertSame('4.000', StockBalance::first()->quantity);
        $this->assertSame('13.3332', $this->balance('inventory'));
        $this->assertSame('-13.3332', $this->balance('receipt_accrual'));
        $this->invalid(fn () => $this->receive($o, '7'));
        $d = $this->request(['due_on' => AccountingLedger::today(), 'tax_rate' => '15', 'items' => [['variant_id' => $this->v->id, 'quantity' => '4']]]);
        $b = app(TradePurchasing::class)->bill($this->u, $r->id, $d);
        $this->assertSame($b->id, app(TradePurchasing::class)->bill($this->u, $r->id, $d)->id);
        $this->assertSame('4.000', StockBalance::first()->quantity);
        $this->assertSame('0.0000', $this->balance('receipt_accrual'));
        $this->assertSame('-15.3300', $this->balance('payable'));
        $this->invalid(fn () => app(TradePurchasing::class)->bill($this->u, $r->id, [...$d, 'request_id' => (string) Str::uuid()]));
        $this->receive($o, '6');
        $this->assertSame('completed', $o->fresh()->status);
    }

    public function test_credit_invoice_payment_return_and_reconciliation(): void
    {
        $this->receive($this->order(), '10');
        $q = $this->quote('2');
        $this->assertSame('10.000', StockBalance::first()->quantity);
        $d = $this->request(['accepted' => true]);
        $invoice = app(TradeSales::class)->invoice($this->u, $q->id, $d);
        $this->assertSame($invoice->id, app(TradeSales::class)->invoice($this->u, $q->id, $d)->id);
        $this->assertSame('8.000', StockBalance::first()->quantity);
        $this->assertSame('20.0000', $this->balance('receivable'));
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $q->id, $this->request(['accepted' => true])));
        app(TradeSales::class)->creditReturn($this->u, $invoice->id, $this->request(['notes' => 'Returned one', 'items' => [['variant_id' => $this->v->id, 'quantity' => '1']]]));
        $this->assertSame('9.000', StockBalance::first()->quantity);
        $this->assertSame('10.0000', $this->balance('receivable'));
        $l = app(AccountingLedger::class);
        $payment = $this->request(['amount' => '10', 'account_id' => $l->account('cash_safe')->id]);
        app(TradeLedger::class)->settle($this->u, $invoice->id, $payment);
        app(TradeLedger::class)->settle($this->u, $invoice->id, $payment);
        $this->assertSame('0.0000', $this->balance('receivable'));
        $this->assertSame('10.0000', $this->balance('cash_safe'));
        $this->invalid(fn () => app(TradeLedger::class)->settle($this->u, $invoice->id, $this->request(['amount' => '1', 'account_id' => $l->account('cash_safe')->id])));
        $report = app(TradeLedger::class)->report($this->u);
        foreach ($report['controls'] as $c) {
            $this->assertSame('0.0000', $c['unallocated']);
        }$f = app(AccountingReports::class)->run($this->u, ['book' => 'training', 'from' => substr(AccountingLedger::today(), 0, 4).'-01-01', 'to' => AccountingLedger::today()]);
        $this->assertSame('0.0000', $f['balance_sheet']['difference']);
        $this->assertSame('0.0000', $f['inventory_reconciliation']['difference']);
    }

    public function test_credit_limit_and_period_failure_are_atomic(): void
    {
        $this->receive($this->order(), '10');
        $q = $this->quote('2');
        app(TradeLedger::class)->credit($this->u, ['customer_id' => $q->customer_id, 'credit_limit' => '10', 'terms_days' => 30, 'active' => true]);
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $q->id, $this->request(['accepted' => true])));
        $this->assertSame('10.000', StockBalance::first()->quantity);
        app(TradeLedger::class)->credit($this->u, ['customer_id' => $q->customer_id, 'credit_limit' => '100', 'terms_days' => 30, 'active' => true]);
        app(AccountingSetup::class)->lockPeriod($this->u, AccountingPeriod::first());
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $q->id, $this->request(['accepted' => true])));
        $this->assertSame(0, TradeDocument::where('kind', 'invoice')->count());
        $this->assertSame('10.000', StockBalance::first()->quantity);
    }

    public function test_supplier_payments_and_opening_allocation(): void
    {
        $r = $this->receive($this->order(), '1');
        $bill = app(TradePurchasing::class)->bill($this->u, $r->id, $this->request(['due_on' => AccountingLedger::today(), 'tax_rate' => '0', 'items' => [['variant_id' => $this->v->id, 'quantity' => '1']]]));
        $l = app(AccountingLedger::class);
        $l->manual($this->u, [...$this->request(), 'book' => 'training', 'branch_id' => $this->w->branch_id, 'description' => 'Capital', 'cashflow' => 'financing', 'lines' => [$l->line('cash_safe', '20'), $l->line('opening_equity', '0', '20')]]);
        app(TradeLedger::class)->settle($this->u, $bill->id, $this->request(['account_id' => $l->account('cash_safe')->id, 'amount' => '1']));
        $this->assertSame('2.3300', app(TradeLedger::class)->outstanding($bill));
        $this->invalid(fn () => app(TradeLedger::class)->settle($this->u, $bill->id, $this->request(['account_id' => $l->account('cash_safe')->id, 'amount' => '3'])));
        $this->assertSame('-2.3300', $this->balance('payable'));
    }

    public function test_api_permissions_and_posted_document_immutability(): void
    {
        $r = $this->receive($this->order(), '1');
        $this->actingAs($this->u)->getJson('/trade/data')->assertOk();
        $this->get('/trade/print/document/'.$r->id)->assertOk()->assertSee($r->number);
        app(TenantContext::class)->id = $this->u->tenant_id;
        $cashier = new User(['name' => 'Cashier', 'email' => 'cashier-trade@example.test', 'password' => 'SecurePassword123']);
        $cashier->tenant_id = $this->u->tenant_id;
        $cashier->role_id = Role::where('name', 'Cashier')->first()->id;
        $cashier->save();
        $this->actingAs($cashier)->getJson('/trade/data')->assertForbidden();
        app(TenantContext::class)->id = $this->u->tenant_id;
        if (DB::getDriverName() === 'pgsql') {
            $failed = false;
            try {
                DB::transaction(fn () => DB::table('trade_documents')->where('id', $r->id)->update(['total' => '0']));
            } catch (QueryException) {
                $failed = true;
            }$this->assertTrue($failed);
        }
    }

    public function test_control_accounts_require_workflows_and_legacy_balances_require_allocation(): void
    {
        $l = app(AccountingLedger::class);
        $entry = [...$this->request(), 'book' => 'training', 'branch_id' => $this->w->branch_id, 'description' => 'Legacy receivable', 'cashflow' => 'none', 'lines' => [$l->line('receivable', '15'), $l->line('opening_equity', '0', '15')]];
        $this->invalid(fn () => $l->manual($this->u, $entry));
        TenantModule::where('key', 'sales')->update(['enabled' => false]);
        $l->manual($this->u, $entry);
        TenantModule::where('key', 'sales')->update(['enabled' => true]);
        $this->receive($this->order(), '10');
        $quote = $this->quote();
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true])));
        $opening = app(TradeLedger::class)->opening($this->u, $this->request(['kind' => 'opening_receivable', 'warehouse_id' => $this->w->id, 'party_id' => $quote->customer_id, 'due_on' => AccountingLedger::today(), 'amount' => '15']));
        $this->assertSame('15.0000', $this->balance('receivable'));
        $this->invalid(fn () => app(TradeLedger::class)->opening($this->u, $this->request(['kind' => 'opening_receivable', 'warehouse_id' => $this->w->id, 'party_id' => $quote->customer_id, 'due_on' => AccountingLedger::today(), 'amount' => '1'])));
        app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $this->assertSame('25.0000', $this->balance('receivable'));
    }

    public function test_cancel_remaining_order_and_closed_period_receipt_rollback(): void
    {
        $order = $this->order();
        $this->receive($order, '1');
        app(PurchaseOrders::class)->transition($this->u, $order->id, 'cancel');
        $this->invalid(fn () => $this->receive($order, '1'));
        $this->assertSame('1.000', StockBalance::first()->quantity);
        $other = $this->order();
        app(AccountingSetup::class)->lockPeriod($this->u, AccountingPeriod::first());
        $this->invalid(fn () => $this->receive($other, '1'));
        $this->assertSame('approved', $other->fresh()->status);
        $this->assertSame(1, TradeDocument::where('kind', 'receipt')->count());
    }

    public function test_partial_bills_clear_exact_accrual_and_block_branch_closure(): void
    {
        $order = $this->order('3');
        $receipt = $this->receive($order, '3');
        $this->invalid(fn () => app(TradeLedger::class)->assertBranchClosable($this->w->branch_id));
        foreach (['1', '2'] as $qty) {
            app(TradePurchasing::class)->bill($this->u, $receipt->id, $this->request(['due_on' => AccountingLedger::today(), 'tax_rate' => '0', 'items' => [['variant_id' => $this->v->id, 'quantity' => $qty]]]));
        }
        $this->assertSame('0.0000', $this->balance('receipt_accrual'));
        $this->assertSame('9.9999', $this->balance('inventory'));
        $this->invalid(fn () => app(TradeLedger::class)->assertBranchClosable($this->w->branch_id));
    }

    public function test_expired_quote_rejects_backdated_acceptance_without_stock_changes(): void
    {
        $this->receive($this->order(), '2');
        $quote = $this->quote();
        $date = AccountingLedger::today();
        $this->travel(1)->days();
        try {
            $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['date' => $date, 'accepted' => true])));
            $this->assertSame(0, TradeDocument::where('kind', 'invoice')->count());
            $this->assertSame('2.000', StockBalance::first()->quantity);
        } finally {
            $this->travelBack();
        }
    }

    public function test_disabled_trade_modules_reject_their_api_actions(): void
    {
        TenantModule::where('key', 'purchasing')->update(['enabled' => false]);
        $this->actingAs($this->u)->postJson('/api/v1/trade/orders', $this->request(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->w->id, 'items' => [['variant_id' => $this->v->id, 'quantity' => '1', 'unit_cost' => '5']]]))->assertForbidden();
        app(TenantContext::class)->id = $this->u->tenant_id;
        TenantModule::where('key', 'sales')->update(['enabled' => false]);
        $this->postJson('/api/v1/trade/customers', ['name' => 'Blocked customer'])->assertForbidden();
        $this->getJson('/api/v1/trade/data')->assertForbidden();
    }

    public function test_foreign_account_settlement_clears_invoice_once(): void
    {
        TenantModule::where('key', 'currencies')->update(['enabled' => true]);
        $rates = app(CurrencyRates::class);
        $currency = $rates->currency($this->u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $rate = $rates->propose($this->u, ['currency_id' => $currency->id, 'effective_on' => AccountingLedger::today(), 'rate' => '0.05', 'source' => 'Test']);
        $rates->approve($this->u, $rate->id);
        $fx = app(ForeignLedger::class);
        $account = $fx->account($this->u, ['branch_id' => $this->w->branch_id, 'currency_id' => $currency->id, 'name' => 'Rand bank', 'kind' => 'bank']);
        $this->receive($this->order(), '2');
        $quote = $this->quote();
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $input = $this->request(['foreign_account_id' => $account->id, 'original_amount' => '200']);
        $ledger = app(TradeLedger::class);
        $doc = $ledger->settle($this->u, $invoice->id, $input);
        $this->assertSame($doc->id, $ledger->settle($this->u, $invoice->id, $input)->id);
        $this->assertSame('0.0000', $ledger->outstanding($invoice));
        $this->assertSame('0.0000', $this->balance('receivable'));
        $this->assertSame(['original' => '200.0000', 'base' => '10.0000'], $fx->balances($account, 'training'));
        $this->assertSame('ZAR', $doc->items[0]['currency']);
    }

    public function test_foreign_invoice_partial_payment_and_return_preserve_original_values(): void
    {
        TenantModule::where('key', 'currencies')->update(['enabled' => true]);
        $rates = app(CurrencyRates::class);
        $currency = $rates->currency($this->u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $rate = $rates->propose($this->u, ['currency_id' => $currency->id, 'effective_on' => AccountingLedger::today(), 'rate' => '0.05', 'source' => 'Original']);
        $rates->approve($this->u, $rate->id);
        $fx = app(ForeignLedger::class);
        $account = $fx->account($this->u, ['branch_id' => $this->w->branch_id, 'currency_id' => $currency->id, 'name' => 'Rand bank', 'kind' => 'bank']);
        $this->receive($this->order(), '2');
        $seed = $this->quote();
        $quote = app(TradeSales::class)->quote($this->u, $this->request(['currency' => 'ZAR', 'warehouse_id' => $this->w->id, 'customer_id' => $seed->customer_id, 'due_on' => AccountingLedger::today(), 'items' => [['variant_id' => $this->v->id, 'quantity' => '2', 'unit_price' => '200', 'discount_percent' => '0']]]));
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $this->assertSame('20.0000', $invoice->total);
        $this->assertSame('400.00', $invoice->currency_snapshot['original_amount']);
        $this->travel(1)->days();
        try {
            $rate = $rates->propose($this->u, ['currency_id' => $currency->id, 'effective_on' => AccountingLedger::today(), 'rate' => '0.06', 'source' => 'Payment']);
            $rates->approve($this->u, $rate->id);
            $ledger = app(TradeLedger::class);
            $payment = $this->request(['foreign_account_id' => $account->id, 'original_amount' => '200']);
            $doc = $ledger->settle($this->u, $invoice->id, $payment);
            $this->assertSame($doc->id, $ledger->settle($this->u, $invoice->id, $payment)->id);
            $this->assertSame('10.0000', $ledger->outstanding($invoice));
            $this->assertSame('200.0000', app(TradeCurrency::class)->outstandingOriginal($invoice));
            $this->assertSame('12.0000', $fx->balances($account, 'training')['base']);
            $this->assertSame('-2.0000', $this->balance('fx_gain'));
            app(TradeSales::class)->creditReturn($this->u, $invoice->id, $this->request(['notes' => 'Returned remainder', 'items' => [['variant_id' => $this->v->id, 'quantity' => '1']]]));
            $this->assertSame('0.0000', $ledger->outstanding($invoice));
            $this->assertSame('0.0000', app(TradeCurrency::class)->outstandingOriginal($invoice));
            $this->assertSame('0.0000', $this->balance('receivable'));
        } finally {
            $this->travelBack();
        }
    }

    public function test_overdue_credit_cannot_be_bypassed_by_backdating(): void
    {
        $this->receive($this->order(), '10');
        $quote = $this->quote();
        app(TradeLedger::class)->credit($this->u, ['customer_id' => $quote->customer_id, 'credit_limit' => '100', 'terms_days' => 0, 'active' => true]);
        app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $this->travel(1)->days();
        $next = $this->quote();
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $next->id, $this->request(['accepted' => true])));
        $this->travelBack();
        $this->assertSame('9.000',StockBalance::first()->quantity);
    }
}

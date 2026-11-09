<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\TradeDocumentDetail;
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
use App\Services\ReportsAnalytics;
use App\Services\StockLedger;
use App\Services\Suppliers;
use App\Services\TenantContext;
use App\Services\TradeAging;
use App\Services\TradeCurrency;
use App\Services\TradeInvoiceEdits;
use App\Services\TradeLedger;
use App\Services\TradePurchasing;
use App\Services\TradeSales;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        DB::table('tenant_subscriptions')->where('tenant_id', $this->u->tenant_id)->update(['status' => 'active']);
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

    private function balance(string|int $role): string
    {
        $l = app(AccountingLedger::class);
        $id = is_int($role) ? $role : $l->account($role)->id;

        return $l->balance($id, 'training', $this->w->branch_id);
    }

    private function quote(string $qty = '1'): TradeDocument
    {
        $c = PosCustomer::firstOrCreate(['name' => 'Customer']);
        app(TradeLedger::class)->credit($this->u, ['customer_id' => $c->id, 'credit_limit' => '100', 'terms_days' => 30, 'active' => true]);

        return app(TradeSales::class)->quote($this->u, $this->request(['warehouse_id' => $this->w->id, 'customer_id' => $c->id, 'due_on' => AccountingLedger::today(), 'items' => [['variant_id' => $this->v->id, 'quantity' => $qty, 'unit_price' => '10', 'discount_percent' => '0']]]));
    }

    public function test_vat_inclusive_preview_matches_saved_quote_without_posting(): void
    {
        $product = $this->v->product;
        $product->update(['tax_class' => 'standard']);
        $company = Company::firstOrFail();
        $company->update(['settings' => [...($company->settings ?? []), 'tax_rate' => '15']]);
        $c = PosCustomer::create(['name' => 'VAT Customer']);
        $d = $this->request(['warehouse_id' => $this->w->id, 'customer_id' => $c->id, 'due_on' => AccountingLedger::today(), 'prices_include_vat' => true, 'items' => [['variant_id' => $this->v->id, 'quantity' => '2', 'unit_price' => '115', 'discount_percent' => '10']]]);
        $preview = app(TradeSales::class)->quote($this->u, $d, null, true);
        $this->assertSame('180.00', $preview['net']);
        $this->assertSame('27.00', $preview['tax']);
        $this->assertSame('207.00', $preview['total']);
        $this->assertSame(0, TradeDocument::count());
        $this->assertSame('0.0000', $this->balance('receivable'));
        $q = app(TradeSales::class)->quote($this->u, $d);
        $this->assertSame($preview['items'], $q->items);
        $exclusive = app(TradeSales::class)->quote($this->u, [...$d, 'prices_include_vat' => false], null, true);
        $this->assertSame('238.05', $exclusive['total']);
    }

    public function test_quote_and_invoice_print_include_the_uploaded_company_logo(): void
    {
        Storage::fake('local');
        $logo = UploadedFile::fake()->image('brand.png', 240, 80);
        $path = $logo->store('company-logos/'.$this->u->tenant_id, 'local');
        Company::firstOrFail()->update(['logo_path' => $path]);
        $this->receive($this->order(), '2');
        $quote = $this->quote();
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        foreach ([$quote, $invoice] as $document) {
            $this->actingAs($this->u)->get('/trade/print/document/'.$document->id)->assertOk()->assertSee('data:image/png;base64,', false)->assertSee('Trade shop logo');
        }
    }

    public function test_quote_preview_and_revision_endpoints_enforce_permissions(): void
    {
        $q = $this->quote();
        $d = $this->request(['warehouse_id' => $q->warehouse_id, 'customer_id' => $q->customer_id, 'due_on' => $q->due_on, 'prices_include_vat' => true, 'items' => [['variant_id' => $this->v->id, 'quantity' => '1', 'unit_price' => '8', 'discount_percent' => '0']]]);
        $this->actingAs($this->u)->postJson('/trade/quote-preview/'.$q->id, $d)->assertOk()->assertJsonPath('data.total', '8.00');
        $revised = $this->postJson('/trade/quotes/'.$q->id, $d)->assertOk()->json('data');
        $this->assertSame($q->id, $revised['parent_id']);
        $this->getJson('/trade/data')->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.quote_closed', false)->assertJsonPath('data.documents.0.number', $q->number);
        $this->postJson('/trade/quotes/'.$q->id, [...$d, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        app(TenantContext::class)->id = $this->u->tenant_id;
        $this->assertSame(2, TradeDocument::where('kind', 'quote')->count());
        $role = $this->u->role;
        $role->update(['permissions' => ['sales.view']]);
        $this->u->unsetRelation('role');
        $this->postJson('/trade/quote-preview', $d)->assertForbidden();
        $this->postJson('/trade/quotes/'.$revised['id'], $d)->assertForbidden();
    }

    public function test_quote_revisions_preserve_history_and_only_latest_can_be_invoiced(): void
    {
        $this->receive($this->order(), '10');
        $old = $this->quote();
        $d = $this->request(['warehouse_id' => $old->warehouse_id, 'customer_id' => $old->customer_id, 'due_on' => $old->due_on, 'items' => [['variant_id' => $this->v->id, 'quantity' => '1', 'unit_price' => '8', 'discount_percent' => '0']]]);
        $q = app(TradeSales::class)->quote($this->u, $d, $old->id);
        $this->assertSame($old->id, $q->parent_id);
        $this->assertSame('10.0000', $old->fresh()->total);
        $this->assertSame('8.0000', $q->total);
        $this->assertSame($q->id, app(TradeSales::class)->quote($this->u, $d, $old->id)->id);
        $this->invalid(fn () => app(TradeSales::class)->invoice($this->u, $old->id, $this->request(['accepted' => true])));
        $this->invalid(fn () => app(TradeSales::class)->quote($this->u, [...$d, 'request_id' => (string) Str::uuid()], $old->id));
        $invoice = app(TradeSales::class)->invoice($this->u, $q->id, $this->request(['accepted' => true]));
        $this->assertSame('8.0000', $invoice->total);
        $this->assertSame('9.000', StockBalance::first()->quantity);
        $this->invalid(fn () => app(TradeSales::class)->quote($this->u, [...$d, 'request_id' => (string) Str::uuid()], $q->id));
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
        $this->assertSame('9.000', StockBalance::first()->quantity);
    }

    private function invoiceEditData(TradeDocument $invoice, string $quantity = '1', string $price = '12'): array
    {
        return $this->request(['warehouse_id' => $invoice->warehouse_id, 'customer_id' => $invoice->customer_id, 'due_on' => $invoice->due_on, 'currency' => $invoice->currency_snapshot['currency'] ?? 'USD', 'notes' => 'Edited invoice', 'items' => [['variant_id' => $this->v->id, 'quantity' => $quantity, 'unit_price' => $price, 'discount_percent' => '0']]]);
    }

    public function test_invoice_edits_keep_one_number_without_creating_a_quote_or_double_posting(): void
    {
        $this->receive($this->order(), '10');
        $quote = $this->quote('2');
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $input = $this->invoiceEditData($invoice, '3');
        $edits = app(TradeInvoiceEdits::class);
        $edited = $edits->amend($this->u, $invoice->id, $input);
        $this->assertSame($invoice->number, $edited->display_number);
        $this->assertSame('36.0000', $edited->total);
        $this->assertSame('36.0000', $this->balance('receivable'));
        $this->assertSame('7.000', StockBalance::firstOrFail()->quantity);
        $this->assertSame('0.0000', app(TradeLedger::class)->outstanding($invoice));
        $this->assertSame(1, TradeDocument::where('kind', 'quote')->count());
        $this->assertSame(1, TradeDocument::current()->where('kind', 'invoice')->count());
        $this->assertSame($edited->id, $edits->amend($this->u, $invoice->id, $input)->id);
        $this->assertSame('7.000', StockBalance::firstOrFail()->quantity);
        $this->invalid(fn () => $edits->amend($this->u, $invoice->id, $this->invoiceEditData($invoice)));
        $this->actingAs($this->u)->getJson('/trade/data')->assertOk()->assertJsonPath('data.documents.0.number', $invoice->number)->assertJsonPath('data.documents.0.invoice_editable', true);
        $this->getJson('/trade/history/'.$edited->id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.number', $invoice->number)->assertJsonPath('data.1.superseded', true);
        $this->get('/trade/print/document/'.$edited->id)->assertOk()->assertSee($invoice->number);
    }

    public function test_invoice_edit_failure_and_locked_period_leave_the_original_untouched(): void
    {
        $this->receive($this->order(), '2');
        $invoice = app(TradeSales::class)->invoice($this->u, $this->quote('2')->id, $this->request(['accepted' => true]));
        $edits = app(TradeInvoiceEdits::class);
        $documents = TradeDocument::count();
        $movements = StockMovement::count();
        $this->invalid(fn () => $edits->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '3')));
        $this->assertSame($documents, TradeDocument::count());
        $this->assertSame($movements, StockMovement::count());
        AccountingPeriod::query()->update(['locked' => true]);
        $this->invalid(fn () => $edits->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '1')));
        $this->assertSame($documents, TradeDocument::count());
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame('20.0000', $this->balance('receivable'));
        $this->assertSame('0.000', StockBalance::firstOrFail()->quantity);
    }

    public function test_price_only_invoice_edit_keeps_original_cost_after_later_purchases(): void
    {
        $this->receive($this->order(), '2');
        $invoice = app(TradeSales::class)->invoice($this->u, $this->quote()->id, $this->request(['accepted' => true]));
        app(StockLedger::class)->post($this->u, $this->request(['warehouse_id' => $this->w->id, 'type' => 'adjustment', 'reason' => 'Later delivery', 'lines' => [['variant_id' => $this->v->id, 'quantity' => '2', 'unit_cost' => '10']]]));
        $before = StockBalance::firstOrFail();
        $cost = $this->balance('cogs');
        app(TradeInvoiceEdits::class)->amend($this->u, $invoice->id, $this->invoiceEditData($invoice));
        $this->assertSame($before->quantity, StockBalance::firstOrFail()->quantity);
        $this->assertSame($before->value, StockBalance::firstOrFail()->value);
        $this->assertSame($cost, $this->balance('cogs'));
    }

    public function test_paid_invoice_only_allows_details_and_keeps_payments_and_stock(): void
    {
        $this->receive($this->order(), '2');
        $invoice = app(TradeSales::class)->invoice($this->u, $this->quote()->id, $this->request(['accepted' => true]));
        app(TradeLedger::class)->settle($this->u, $invoice->id, $this->request(['account_id' => app(AccountingLedger::class)->account('cash_safe')->id, 'amount' => '5']));
        $edits = app(TradeInvoiceEdits::class);
        $this->invalid(fn () => $edits->amend($this->u, $invoice->id, $this->invoiceEditData($invoice)));
        $data = $this->request(['reference' => 'PO-SCHOOL', 'notes' => 'Updated school contact']);
        $edits->details($this->u, $invoice->id, $data);
        $edits->details($this->u, $invoice->id, $data);
        $this->assertSame(1, TradeDocumentDetail::count());
        $this->assertSame('5.0000', app(TradeLedger::class)->outstanding($invoice));
        $this->assertSame('1.000', StockBalance::firstOrFail()->quantity);
        $this->actingAs($this->u)->getJson('/trade/data')->assertOk()->assertJsonPath('data.documents.1.reference', 'PO-SCHOOL')->assertJsonPath('data.documents.1.invoice_editable', false);
        $this->get('/trade/print/document/'.$invoice->id)->assertOk()->assertSee('PO-SCHOOL')->assertSee('Updated school contact');
        $this->postJson('/trade/invoice-edits/'.$invoice->id, $this->invoiceEditData($invoice))->assertUnprocessable();
    }

    public function test_invoice_edit_permissions_and_foreign_amounts_are_preserved(): void
    {
        TenantModule::where('key', 'currencies')->update(['enabled' => true]);
        $rates = app(CurrencyRates::class);
        $currency = $rates->currency($this->u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $rate = $rates->propose($this->u, ['currency_id' => $currency->id, 'effective_on' => AccountingLedger::today(), 'rate' => '0.05', 'source' => 'Original']);
        $rates->approve($this->u, $rate->id);
        $this->receive($this->order(), '2');
        $seed = $this->quote();
        $q = app(TradeSales::class)->quote($this->u, [...$this->invoiceEditData($seed, '1', '200'), 'currency' => 'ZAR']);
        $invoice = app(TradeSales::class)->invoice($this->u, $q->id, $this->request(['accepted' => true]));
        $edited = app(TradeInvoiceEdits::class)->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '1', '300'));
        $this->assertSame('15.0000', $edited->total);
        $this->assertSame('300.00', $edited->currency_snapshot['original_amount']);
        $this->assertSame('0.0000', app(TradeCurrency::class)->outstandingOriginal($invoice));
        $this->assertSame('15.0000', $this->balance('receivable'));
        $role = $this->u->role;
        $role->update(['permissions' => ['sales.view']]);
        $this->u->unsetRelation('role');
        $this->actingAs($this->u)->postJson('/trade/invoice-edits/'.$edited->id, $this->invoiceEditData($edited))->assertForbidden();
        $this->postJson('/trade/invoice-details/'.$edited->id, $this->request(['reference' => 'No permission']))->assertForbidden();
    }

    public function test_service_invoice_edits_need_no_stock_and_create_no_new_quotation(): void
    {
        $this->v->product->update(['item_type' => 'service']);
        $quote = $this->quote();
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $edited = app(TradeInvoiceEdits::class)->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '2'));
        $this->assertSame($invoice->number, $edited->display_number);
        $this->assertSame('24.0000', $this->balance('receivable'));
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, TradeDocument::where('kind', 'quote')->count());
        $report = app(TradeLedger::class)->report($this->u);
        $this->assertCount(1, $report['aging']);
        $this->assertSame($invoice->number, $report['aging'][0]['number']);
    }

    public function test_historical_ageing_ignores_later_payments_and_reconciles_to_the_cutoff(): void
    {
        $this->v->product->update(['item_type' => 'service']);
        $cutoff = CarbonImmutable::parse(AccountingLedger::today())->subDays(5)->format('Y-m-d');
        $this->travelTo(now()->subDays(5));
        $quote = $this->quote('2');
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $this->travelBack();
        app(TradeLedger::class)->settle($this->u, $invoice->id, $this->request(['account_id' => app(AccountingLedger::class)->account('cash_safe')->id, 'amount' => '5']));
        $old = app(TradeAging::class)->run($this->u, ['to' => $cutoff]);
        $now = app(TradeAging::class)->run($this->u);
        $this->assertSame('20.0000', $old['totals']['receivable']['total']);
        $this->assertSame('15.0000', $now['totals']['receivable']['total']);
        $this->assertSame('0.0000', collect($old['controls'])->firstWhere('role', 'receivable')['unallocated']);
        $this->assertSame('0.0000', collect($now['controls'])->firstWhere('role', 'receivable')['unallocated']);
    }

    public function test_historical_ageing_uses_the_invoice_version_effective_at_cutoff(): void
    {
        $this->v->product->update(['item_type' => 'service']);
        $cutoff = CarbonImmutable::parse(AccountingLedger::today())->subDays(5)->format('Y-m-d');
        $this->travelTo(now()->subDays(5));
        $quote = $this->quote('2');
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        $this->travelBack();
        $edited = app(TradeInvoiceEdits::class)->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '3'));
        $old = app(TradeAging::class)->run($this->u, ['to' => $cutoff]);
        $now = app(TradeAging::class)->run($this->u);
        $this->assertCount(1, $old['aging']);
        $this->assertSame($invoice->id, $old['aging'][0]['id']);
        $this->assertSame('20.0000', $old['totals']['receivable']['total']);
        $this->assertCount(1, $now['aging']);
        $this->assertSame($edited->id, $now['aging'][0]['id']);
        $this->assertSame('36.0000', $now['totals']['receivable']['total']);
        $this->assertSame($invoice->number, $now['aging'][0]['number']);
        $this->assertSame('0.0000', collect($old['controls'])->firstWhere('role', 'receivable')['unallocated']);
    }

    public function test_ageing_bucket_boundaries_keep_debtors_and_creditors_separate(): void
    {
        $today = CarbonImmutable::parse(AccountingLedger::today());
        $customer = PosCustomer::create(['name' => 'Ageing customer']);
        foreach ([0, 1, 30, 31, 60, 61, 90, 91] as $days) {
            foreach (['opening_receivable', 'opening_payable'] as $kind) {
                app(TradeLedger::class)->document($this->u, $kind, $this->request(['warehouse_id' => $this->w->id, 'date' => $today->subDays(100)->format('Y-m-d'), 'due_on' => $today->subDays($days)->format('Y-m-d'), 'party_name' => $kind === 'opening_receivable' ? $customer->name : $this->supplier->name, 'customer_id' => $kind === 'opening_receivable' ? $customer->id : null, 'supplier_id' => $kind === 'opening_payable' ? $this->supplier->id : null]), [], '10', '0', '10', []);
            }
        }
        $r = app(TradeAging::class)->run($this->u);
        foreach (['receivable', 'payable'] as $role) {
            $this->assertSame(['current' => '10.0000', '1–30' => '20.0000', '31–60' => '20.0000', '61–90' => '20.0000', '90+' => '10.0000', 'total' => '80.0000'], $r['totals'][$role]);
        }
        $this->assertCount(2, $r['parties']);
    }

    public function test_report_centre_sales_net_revisions_exports_and_procurement_values(): void
    {
        TenantModule::where('key', 'reports')->update(['enabled' => true]);
        $this->v->product->update(['item_type' => 'service']);
        $invoice = app(TradeSales::class)->invoice($this->u, $this->quote('2')->id, $this->request(['accepted' => true]));
        app(TradeInvoiceEdits::class)->amend($this->u, $invoice->id, $this->invoiceEditData($invoice, '3'));
        $filters = ['from' => AccountingLedger::today(), 'to' => AccountingLedger::today(), 'book' => 'training'];
        $report = app(ReportsAnalytics::class)->run($this->u, $filters);
        $this->assertSame('36.0000', $report['business']['sales']['total']);
        $this->assertCount(3, $report['business']['sales']['documents']);
        $this->assertSame('36.0000', $report['accounting']['profit_loss']['income']);
        $this->assertCount(1, $report['business']['aging']['aging']);
        $this->actingAs($this->u)->get('/reports?'.http_build_query($filters))->assertOk();
        foreach (['debtors', 'creditors', 'profit_loss', 'balance_sheet', 'trial_balance', 'general_ledger', 'cash_flow', 'sales', 'procurement'] as $name) {
            $this->get('/reports/export?'.http_build_query([...$filters, 'report' => $name]))->assertOk()->assertDownload('bretail-'.$name.'-'.$filters['to'].'.csv');
        }
        app(TenantContext::class)->id = $this->u->tenant_id;
        $this->v->product->update(['item_type' => 'stock']);
        $this->order('3');
        $purchase = app(ReportsAnalytics::class)->run($this->u, $filters)['business']['procurement'];
        $this->assertSame('9.9999', $purchase['orders'][0]['total']);
    }

    public function test_report_centre_respects_branch_scope_and_finance_permissions(): void
    {
        TenantModule::where('key', 'reports')->update(['enabled' => true]);
        $this->v->product->update(['item_type' => 'service']);
        app(TradeSales::class)->invoice($this->u, $this->quote()->id, $this->request(['accepted' => true]));
        $branch = Branch::create(['name' => 'Restricted branch', 'code' => 'RESTRICT']);
        $role = Role::create(['name' => 'Report reader', 'company_wide' => false, 'permissions' => ['reports.view', 'reports.export', 'sales.view']]);
        $reader = new User(['name' => 'Reader', 'email' => 'report-reader@example.test', 'password' => 'SecurePassword123']);
        $reader->tenant_id = $this->u->tenant_id;
        $reader->role_id = $role->id;
        $reader->save();
        $reader->branches()->attach($branch->id, ['tenant_id' => $this->u->tenant_id]);
        $filters = ['from' => AccountingLedger::today(), 'to' => AccountingLedger::today(), 'book' => 'training'];
        $r = app(ReportsAnalytics::class)->run($reader, $filters);
        $this->assertNull($r['accounting']);
        $this->assertNull($r['business']['procurement']);
        $this->assertSame('0.0000', $r['business']['sales']['total']);
        $this->assertEmpty($r['business']['aging']['aging']);
        $this->actingAs($reader)->get('/reports/export?'.http_build_query([...$filters, 'report' => 'balance_sheet']))->assertForbidden();
        $this->get('/reports/export?'.http_build_query([...$filters, 'report' => 'creditors']))->assertForbidden();
        $this->get('/reports?'.http_build_query([...$filters, 'branch_id' => $this->w->branch_id]))->assertNotFound();
    }

    public function test_company_contact_tax_and_banking_details_print_on_quotes_and_invoices_only(): void
    {
        $company = Company::firstOrFail();
        $settings = [...$company->settings, 'address' => "24 Business Road\nHarare, Zimbabwe", 'phone' => '+263 242 123456', 'alternative_phone' => '+263 771 234567', 'contact_email' => 'accounts@trade.test', 'tax_id' => 'TIN-123456', 'vat_number' => 'VAT-654321', 'banking_details' => "Bank: Example Bank\nAccount name: Trade shop\nAccount number: 00123456789\nCurrency: USD"];
        $this->actingAs($this->u)->patch('/company', ['name' => $company->name, 'settings' => $settings])->assertSessionHasNoErrors();
        app(TenantContext::class)->id = $this->u->tenant_id;
        $this->assertSame('VAT-654321', $company->fresh()->settings['vat_number']);
        $this->assertSame($settings['banking_details'], $company->fresh()->settings['banking_details']);
        $this->receive($this->order(), '2');
        $quote = $this->quote();
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));
        foreach ([$quote, $invoice] as $doc) {
            $this->actingAs($this->u)->get('/trade/print/document/'.$doc->id)->assertOk()
                ->assertSee('24 Business Road')->assertSee('+263 242 123456')->assertSee('+263 771 234567')
                ->assertSee('accounts@trade.test')->assertSee('Tax ID: TIN-123456')->assertSee('VAT Number: VAT-654321')
                ->assertSee('Banking details')->assertSee('00123456789');
        }
        app(TenantContext::class)->id = $this->u->tenant_id;
        $order = $this->order();
        $this->get('/trade/print/order/'.$order->id)->assertOk()->assertSee('VAT Number: VAT-654321')->assertDontSee('00123456789')->assertDontSee('Banking details');
        $this->patch('/company', ['name' => $company->name, 'settings' => ['invoice_prefix' => 'INV', 'banking_details' => str_repeat('x', 2001)]])->assertSessionHasErrors('settings.banking_details');
    }

    public function test_invoice_physical_inventory_without_stock_uses_fallback_cost_and_allows_negative_balance(): void
    {
        $this->assertSame(0, StockBalance::count());
        $quote = $this->quote('2');
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));

        $this->assertSame('20.0000', $invoice->total);
        $balance = StockBalance::where('variant_id', $this->v->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame('-2.000', $balance->quantity);
        $this->assertSame('-6.6666', $balance->value);
        $this->assertSame('3.3333', $balance->average_cost);

        $this->assertSame('20.0000', $this->balance('receivable'));
        $this->assertSame('-20.0000', $this->balance('sales'));
        $this->assertSame('6.6666', $this->balance('cogs'));
        $this->assertSame('-6.6666', $this->balance('inventory'));

        $rep = app(StockLedger::class)->reconcile($this->u, $this->w->id);
        $this->assertTrue($rep['balanced']);
    }

    public function test_procurement_grv_and_bill_direct_to_cost_of_sales_account(): void
    {
        $cogsAccount = app(AccountingLedger::class)->account('cogs');
        $d = [
            'request_id' => (string) Str::uuid(),
            'warehouse_id' => $this->w->id,
            'supplier_id' => $this->supplier->id,
            'date' => AccountingLedger::today(),
            'expected_on' => AccountingLedger::today(),
            'items' => [
                [
                    'account_id' => $cogsAccount->id,
                    'description' => 'Structural steel plates',
                    'quantity' => '5',
                    'unit_cost' => '100',
                ],
            ],
        ];
        $order = app(PurchaseOrders::class)->create($this->u, $d);
        app(PurchaseOrders::class)->transition($this->u, $order->id, 'approve');

        // Receive against the order (GRV)
        $receipt = app(TradePurchasing::class)->receive($this->u, $order->id, [
            'request_id' => (string) Str::uuid(),
            'date' => AccountingLedger::today(),
            'reference' => 'GRV-STEEL-01',
            'items' => [
                [
                    'account_id' => $cogsAccount->id,
                    'quantity' => '5',
                ],
            ],
        ]);

        $this->assertSame('500.0000', $receipt->net);
        $this->assertSame('500.0000', $this->balance($cogsAccount->id));
        $this->assertSame('-500.0000', $this->balance('receipt_accrual'));
        $this->assertSame(0, StockBalance::where('warehouse_id', $this->w->id)->count());

        // Bill against the receipt (Supplier Invoice)
        $bill = app(TradePurchasing::class)->bill($this->u, $receipt->id, [
            'request_id' => (string) Str::uuid(),
            'date' => AccountingLedger::today(),
            'due_on' => AccountingLedger::today(),
            'reference' => 'INV-STEEL-SUP-01',
            'tax_rate' => '15',
            'items' => [
                [
                    'account_id' => $cogsAccount->id,
                    'quantity' => '5',
                ],
            ],
        ]);

        $this->assertSame('500.0000', $bill->net);
        $this->assertSame('75.0000', $bill->tax);
        $this->assertSame('575.0000', $bill->total);
        $this->assertSame('0.0000', $this->balance('receipt_accrual'));
        $this->assertSame('75.0000', $this->balance('tax_receivable'));
        $this->assertSame('-575.0000', $this->balance('payable'));
    }

    public function test_direct_supplier_bill_to_cost_of_sales(): void
    {
        $cogsAccount = app(AccountingLedger::class)->account('cogs');
        $res = $this->actingAs($this->u)->postJson('/trade/direct-bills', [
            'request_id' => (string) Str::uuid(),
            'warehouse_id' => $this->w->id,
            'supplier_id' => $this->supplier->id,
            'date' => AccountingLedger::today(),
            'due_on' => AccountingLedger::today(),
            'reference' => 'STEEL-DIRECT-99',
            'tax_rate' => '0',
            'items' => [
                [
                    'account_id' => $cogsAccount->id,
                    'description' => 'Direct steel beam delivery',
                    'quantity' => '2',
                    'unit_cost' => '250',
                ],
            ],
        ])->assertOk();

        $this->assertSame('500.0000', $res->json('data.total'));
        app(TenantContext::class)->id = $this->u->tenant_id;
        $this->assertSame('-500.0000', $this->balance('payable'));
        $this->assertSame('500.0000', $this->balance($cogsAccount->id));
    }

    public function test_trade_document_share_info_and_whatsapp_link(): void
    {
        $this->receive($this->order(), '5');
        $q = $this->quote();
        $this->actingAs($this->u);

        $res = $this->getJson("/trade/documents/{$q->id}/share-info")
            ->assertOk();

        $res->assertJsonStructure([
            'document_id',
            'number',
            'kind',
            'party_name',
            'total',
            'currency',
            'recipient_email',
            'recipient_phone',
            'signed_url',
            'whatsapp_url',
            'default_subject',
            'default_message',
        ]);

        $this->assertSame($q->id, $res->json('document_id'));
        $this->assertStringContainsString('https://wa.me/', $res->json('whatsapp_url'));
        $this->assertStringContainsString('/portal/document/'.$q->id, $res->json('signed_url'));
    }

    public function test_trade_document_send_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->receive($this->order(), '5');
        $q = $this->quote();
        $this->actingAs($this->u);

        $res = $this->postJson("/trade/documents/{$q->id}/send-email", [
            'email' => 'client@hararetrading.co.zw',
            'subject' => 'Official Quote #'.$q->number,
            'message' => 'Please find your quotation attached.',
        ])->assertOk();

        $res->assertJsonPath('success', true);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TradeDocumentMail::class, function ($mail) use ($q) {
            return $mail->hasTo('client@hararetrading.co.zw') &&
                   $mail->document->id === $q->id;
        });
    }

    public function test_public_document_signed_portal_view(): void
    {
        $this->receive($this->order(), '5');
        $q = $this->quote();

        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'portal.document.public',
            now()->addDays(60),
            ['id' => $q->id]
        );

        // Guest can view document via valid signed URL
        $this->get($signedUrl)
            ->assertOk()
            ->assertSee($q->number)
            ->assertSee('WhatsApp')
            ->assertSee('Print / Save PDF');

        // Tampered signature returns 403
        $tamperedUrl = $signedUrl.'invalid';
        $this->get($tamperedUrl)->assertForbidden();
    }

    public function test_paynow_initiate_and_webhook_settlement_on_invoice(): void
    {
        $this->receive($this->order(), '5');
        $quote = $this->quote();
        $invoice = app(TradeSales::class)->invoice($this->u, $quote->id, $this->request(['accepted' => true]));

        $initRes = $this->postJson("/portal/document/{$invoice->id}/paynow", [
            'email' => 'buyer@zimtrade.co.zw',
            'phone' => '0771234567',
        ])->assertOk();

        $ref = $initRes->json('reference');
        $this->assertNotEmpty($ref);
        $this->assertDatabaseHas('paynow_transactions', [
            'document_id' => $invoice->id,
            'reference' => $ref,
            'status' => 'initiated',
        ]);

        // Paynow sends IPN callback indicating payment completed
        $this->postJson('/portal/paynow/webhook', [
            'reference' => $ref,
            'paynowreference' => 'PN-987654',
            'amount' => '10.00',
            'status' => 'Paid',
        ])->assertOk();

        $this->assertDatabaseHas('paynow_transactions', [
            'reference' => $ref,
            'status' => 'paid',
            'paynow_reference' => 'PN-987654',
        ]);

        $ledger = app(TradeLedger::class);
        $this->assertSame('0.0000', $ledger->outstanding($invoice));
    }

    public function test_paynow_quotation_auto_invoices_and_settles(): void
    {
        $this->receive($this->order(), '5');
        $quote = $this->quote();

        $initRes = $this->postJson("/portal/document/{$quote->id}/paynow", [
            'email' => 'client@quotes.co.zw',
        ])->assertOk();

        $ref = $initRes->json('reference');

        // Customer pays quotation online
        $this->postJson('/portal/paynow/webhook', [
            'reference' => $ref,
            'paynowreference' => 'PN-QUOTE-1234',
            'amount' => '10.00',
            'status' => 'Paid',
        ])->assertOk();

        $this->assertDatabaseHas('paynow_transactions', [
            'reference' => $ref,
            'status' => 'paid',
        ]);

        // Quotation should now have a corresponding paid invoice
        $invoice = TradeDocument::where('parent_id', $quote->id)->where('kind', 'invoice')->first();
        $this->assertNotNull($invoice);
        $ledger = app(TradeLedger::class);
        $this->assertSame('0.0000', $ledger->outstanding($invoice));
    }
}

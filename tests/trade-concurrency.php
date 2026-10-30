<?php

// Standalone PostgreSQL integration check. Only the disposable test database is used.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => '55432', 'database.connections.pgsql.database' => 'bretail_test', 'database.connections.pgsql.username' => 'bretail', 'database.connections.pgsql.password' => '']);
Illuminate\Support\Facades\DB::purge('pgsql');

use App\Models\{PosCustomer, StockBalance, Supplier, Tenant, TenantModule, TradeDocument, User, Warehouse};
use App\Services\{AccountingLedger, AccountingSetup, Catalog, Onboarding, PurchaseOrders, Suppliers, TenantContext, TradeLedger, TradePurchasing, TradeSales};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function input(array $extra = []): array {
    return ['request_id' => (string) Str::uuid(), 'date' => AccountingLedger::today(), 'reference' => (string) Str::uuid(), ...$extra];
}

if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    app(TenantContext::class)->id = $job['tenant'];
    $u = User::findOrFail($job['user']);
    echo "READY\n"; flush();
    try {
        $doc = $job['action'] === 'receive'
            ? app(TradePurchasing::class)->receive($u, $job['id'], $job['input'])
            : app(TradeSales::class)->invoice($u, $job['id'], $job['input']);
        echo json_encode(['ok' => true, 'id' => $doc->id]);
    } catch (Illuminate\Validation\ValidationException $e) {
        echo json_encode(['ok' => false, 'errors' => $e->errors()]);
    }
    exit;
}

function race(User $u, array $jobs): array {
    $workers = [];
    DB::beginTransaction();
    Tenant::whereKey($u->tenant_id)->lockForUpdate()->firstOrFail();
    try {
        foreach ($jobs as $job) {
            $payload = base64_encode(json_encode(['tenant' => $u->tenant_id, 'user' => $u->id, ...$job]));
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $payload], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            check(is_resource($process), 'Worker did not start');
            fclose($pipes[0]);
            if (trim(fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker did not become ready: '.stream_get_contents($pipes[2]));
            $workers[] = [$process, $pipes];
        }
    } finally {
        DB::commit();
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Worker failed: '.$err.$out);
        $results[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }
    return $results;
}

$u = app(Onboarding::class)->register(['name'=>'Race Owner','email'=>'race-'.Str::uuid().'@example.test','password'=>'SecurePassword123','company_name'=>'Concurrency verification','industry'=>'general','base_currency'=>'USD','branch_name'=>'Main','branch_code'=>'MAIN','warehouse_name'=>'Main']);
TenantModule::whereIn('key',['inventory','accounting','purchasing','sales'])->update(['enabled'=>true]);
$w = Warehouse::firstOrFail();
$today = AccountingLedger::today();
app(AccountingSetup::class)->initialize($u,['name'=>'FY','starts_on'=>substr($today,0,4).'-01-01','ends_on'=>substr($today,0,4).'-12-31']);
$s = app(Suppliers::class)->save($u,['code'=>'SUP','name'=>'Supplier','terms_days'=>30,'active'=>true]);
$p = app(Catalog::class)->product($u,['name'=>'Race item','unit'=>'each','tax_class'=>'zero','warranty_months'=>0,'active'=>true,'attributes'=>[]]);
$v = app(Catalog::class)->variant($u,$p,['name'=>'Standard','sku'=>'RACE','cost_price'=>'5','selling_price'=>'10','wholesale_price'=>'10','minimum_stock'=>'0','reorder_level'=>'0','active'=>true,'attributes'=>[]]);
$order = app(PurchaseOrders::class)->create($u,input(['supplier_id'=>$s->id,'warehouse_id'=>$w->id,'items'=>[['variant_id'=>$v->id,'quantity'=>'2','unit_cost'=>'5']]]));
app(PurchaseOrders::class)->transition($u,$order->id,'approve');
$receipt = input(['items'=>[['variant_id'=>$v->id,'quantity'=>'1']]]);
$job = ['action'=>'receive','id'=>$order->id,'input'=>$receipt];
$r = race($u,[$job,$job]);
check($r[0]['ok'] && $r[1]['ok'] && $r[0]['id']===$r[1]['id'], 'Concurrent retries did not return one receipt');
check(StockBalance::firstOrFail()->quantity==='1.000', 'Duplicate receipt changed stock twice');
echo "PASS concurrent receipt retries\n";
$r = race($u,[['action'=>'receive','id'=>$order->id,'input'=>input(['items'=>[['variant_id'=>$v->id,'quantity'=>'1']]])],['action'=>'receive','id'=>$order->id,'input'=>input(['items'=>[['variant_id'=>$v->id,'quantity'=>'1']]])]]);
check(count(array_filter($r,fn($x)=>$x['ok']))===1, 'Concurrent receipts exceeded order');
check(StockBalance::firstOrFail()->quantity==='2.000', 'Receipt stock mismatch');
echo "PASS competing receipts\n";
$c = PosCustomer::create(['name'=>'Credit customer']);
app(TradeLedger::class)->credit($u,['customer_id'=>$c->id,'credit_limit'=>'10','terms_days'=>30,'active'=>true]);
$quotes=[];
foreach ([1,2] as $_) $quotes[]=app(TradeSales::class)->quote($u,input(['warehouse_id'=>$w->id,'customer_id'=>$c->id,'due_on'=>$today,'items'=>[['variant_id'=>$v->id,'quantity'=>'1','unit_price'=>'10','discount_percent'=>'0']]]));
$r = race($u,array_map(fn($q)=>['action'=>'invoice','id'=>$q->id,'input'=>input(['accepted'=>true])],$quotes));
check(count(array_filter($r,fn($x)=>$x['ok']))===1, 'Concurrent invoices bypassed credit limit');
check(TradeDocument::where('kind','invoice')->count()===1 && StockBalance::firstOrFail()->quantity==='1.000','Invoice/stock mismatch');
check(app(AccountingLedger::class)->balance(app(AccountingLedger::class)->account('receivable')->id,'training')==='10.0000','Receivable mismatch');
echo "PASS concurrent credit limit and atomic stock posting\n";
app(TradeLedger::class)->credit($u,['customer_id'=>$c->id,'credit_limit'=>'100','terms_days'=>30,'active'=>true]);
$quotes=[];
foreach ([1,2] as $_) $quotes[]=app(TradeSales::class)->quote($u,input(['warehouse_id'=>$w->id,'customer_id'=>$c->id,'due_on'=>$today,'items'=>[['variant_id'=>$v->id,'quantity'=>'1','unit_price'=>'10','discount_percent'=>'0']]]));
$r = race($u,array_map(fn($q)=>['action'=>'invoice','id'=>$q->id,'input'=>input(['accepted'=>true])],$quotes));
check(count(array_filter($r,fn($x)=>$x['ok']))===1, 'Concurrent invoices oversold last unit');
check(TradeDocument::where('kind','invoice')->count()===2 && StockBalance::firstOrFail()->quantity==='0.000','Last-unit invoice/stock mismatch');
echo "PASS competing invoices for last stock unit\n";

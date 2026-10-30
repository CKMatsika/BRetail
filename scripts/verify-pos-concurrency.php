<?php

use App\Models\PosPayment;
use App\Models\PosSale;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\PosCart;
use App\Services\PosShifts;
use App\Services\StockLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'bretail_dev') {
    throw new RuntimeException('Use only the isolated local bretail_dev database.');
}
$owner = app(Onboarding::class)->register(['name' => 'POS race verification', 'email' => 'pos-race-'.Str::uuid().'@example.test', 'password' => Str::password(24), 'company_name' => 'Isolated POS concurrency training', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Test', 'branch_code' => 'TEST', 'warehouse_name' => 'Test']);
TenantModule::whereIn('key', ['inventory', 'pos'])->update(['enabled' => true]);
$w = Warehouse::first();
$c = app(Catalog::class);
$p = $c->product($owner, ['name' => 'Last item', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => [], 'active' => true]);
$v = $c->variant($owner, $p, ['name' => 'Standard', 'sku' => 'LAST', 'cost_price' => '10', 'selling_price' => '20', 'wholesale_price' => '18', 'reorder_level' => '0', 'minimum_stock' => '0', 'attributes' => [], 'active' => true]);
$stock = fn () => app(StockLedger::class)->post($owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'type' => 'adjustment', 'reference' => 'TEST', 'reason' => 'Isolated training verification', 'lines' => [['variant_id' => $v->id, 'quantity' => '1', 'unit_cost' => '10']]]);
$stock();
if (in_array('--accounting', $argv, true)) {
    TenantModule::where('key', 'accounting')->update(['enabled' => true]);
    $today=\App\Services\AccountingLedger::today();
    app(\App\Services\AccountingSetup::class)->initialize($owner,['name'=>'Race verification','starts_on'=>substr($today,0,4).'-01-01','ends_on'=>substr($today,0,4).'-12-31']);
    app(\App\Services\AccountingSetup::class)->activate($owner,['opening_stock_confirmed'=>true,'base_currency_precision_confirmed'=>true]);
}
$cashiers = [];
foreach (range(1, 2) as $i) {
    $u = new User(['name' => 'Cashier '.$i, 'email' => Str::uuid().'@example.test', 'password' => Str::password(24)]);
    $u->tenant_id = $owner->tenant_id;
    $u->role_id = Role::where('name', 'Cashier')->first()->id;
    $u->save();
    $u->branches()->attach($w->branch_id, ['tenant_id' => $owner->tenant_id]);
    $s = app(PosShifts::class)->open($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'opening_cash' => '0']);
    $cashiers[] = ['user' => $u, 'session' => $s];
}
$make = function ($entry, $id) use ($w, $v) {
    $cart = ['warehouse_id' => $w->id, 'lines' => [['variant_id' => $v->id, 'quantity' => '1']]];
    $quote = app(PosCart::class)->quote($entry['user'], $cart);

    return [...$cart, 'request_id' => $id, 'session_id' => $entry['session']->id, 'quote_token' => $quote['quote_token'], 'payments' => [['method' => 'cash', 'amount' => $quote['total']]]];
};
function posRace(array $jobs): array
{
    $children = [];
    foreach ($jobs as $job) {
        $process = proc_open([PHP_BINARY, __DIR__.'/pos-worker.php', (string) $job['user']->id, base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch POS worker.');
        }$children[] = ['process' => $process, 'pipes' => $pipes];
    }foreach ($children as $c) {
        if (trim((string) fgets($c['pipes'][1])) !== 'READY') {
            throw new RuntimeException('Worker init failed: '.stream_get_contents($c['pipes'][2]));
        }
    }foreach ($children as $c) {
        fwrite($c['pipes'][0], "GO\n");
        fclose($c['pipes'][0]);
    }$results = [];
    foreach ($children as $c) {
        $out = stream_get_contents($c['pipes'][1]);
        $err = stream_get_contents($c['pipes'][2]);
        fclose($c['pipes'][1]);
        fclose($c['pipes'][2]);
        $exit = proc_close($c['process']);
        if ($exit !== 0) {
            throw new RuntimeException($err.$out);
        }$results[] = json_decode(trim($out), true, 64, JSON_THROW_ON_ERROR);
    }

return $results;
}
$first = posRace(array_map(fn ($entry) => ['user' => $entry['user'], 'payload' => $make($entry, (string) Str::uuid())], $cashiers));
$statuses = array_column($first, 'status');
sort($statuses);
if ($statuses !== ['posted', 'rejected'] || PosSale::count() !== 1 || PosPayment::count() !== 1 || StockBalance::first()->quantity !== '0.000') {
    throw new RuntimeException('Last-item POS race failed.');
}
$stock();
$payload = $make($cashiers[0], (string) Str::uuid());
$retry = posRace([['user' => $cashiers[0]['user'], 'payload' => $payload], ['user' => $cashiers[0]['user'], 'payload' => $payload]]);
if (array_column($retry, 'status') !== ['posted', 'posted'] || $retry[0]['id'] !== $retry[1]['id'] || PosSale::count() !== 2 || PosPayment::count() !== 2 || StockBalance::first()->quantity !== '0.000') {
    throw new RuntimeException('POS identical retry race failed.');
}$reconcile = app(StockLedger::class)->reconcile($owner, $w->id);
if (! $reconcile['balanced']) {
    throw new RuntimeException('POS stock does not reconcile.');
}
$financial=null;
if (in_array('--accounting',$argv,true)) {
    $financial=app(\App\Services\AccountingReports::class)->run($owner,['book'=>'live','from'=>substr(\App\Services\AccountingLedger::today(),0,4).'-01-01','to'=>\App\Services\AccountingLedger::today()]);
    if(\App\Models\JournalBatch::where('book','live')->where('source_type','pos_sale')->count()!==2 || $financial['balance_sheet']['difference']!=='0.0000' || $financial['inventory_reconciliation']['difference']!=='0.0000')throw new RuntimeException('Concurrent financial posting failed to reconcile.');
}
echo json_encode(['last_item' => $first, 'same_request' => $retry, 'reconciliation' => $reconcile,'financial'=>$financial?['balance_sheet'=>$financial['balance_sheet'],'inventory'=>$financial['inventory_reconciliation']]:null],JSON_PRETTY_PRINT)."\n";

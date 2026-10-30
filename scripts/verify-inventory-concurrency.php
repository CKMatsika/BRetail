<?php

use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\TenantModule;
use App\Models\Warehouse;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\StockLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'bretail_dev') {
    throw new RuntimeException('Run only against the isolated local bretail_dev database.');
}
$user = app(Onboarding::class)->register(['name' => 'Concurrency verification', 'email' => 'concurrency-'.Str::uuid().'@example.test', 'password' => Str::password(24), 'company_name' => 'Isolated inventory concurrency verification', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Test branch', 'branch_code' => 'TEST', 'warehouse_name' => 'Test warehouse']);
TenantModule::where('key', 'inventory')->update(['enabled' => true]);
$warehouse = Warehouse::firstOrFail();
$product = app(Catalog::class)->product($user, ['name' => 'Concurrency item', 'unit' => 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => [], 'active' => true]);
$variant = app(Catalog::class)->variant($user, $product, ['name' => 'Standard', 'sku' => 'CONCURRENCY', 'attributes' => [], 'cost_price' => '10', 'selling_price' => '15', 'wholesale_price' => '12', 'reorder_level' => '0', 'minimum_stock' => '0', 'active' => true]);
app(StockLedger::class)->post($user, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id, 'type' => 'opening', 'reference' => 'TEST OPEN', 'reason' => 'Isolated concurrency verification', 'lines' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_cost' => '10']]]);
function race($user, $warehouse, $variant, array $ids, string $quantity): array
{
    $children = [];
    foreach ($ids as $id) {
        $process = proc_open([PHP_BINARY, __DIR__.'/inventory-worker.php', (string) $user->id, (string) $warehouse->id, (string) $variant->id, $id, $quantity], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch stock worker.');
        }$children[] = ['process' => $process, 'pipes' => $pipes];
    }
    foreach ($children as $child) {
        if (trim((string) fgets($child['pipes'][1])) !== 'READY') {
            throw new RuntimeException('Worker did not initialize: '.stream_get_contents($child['pipes'][2]));
        }
    }
    // Both workers start against the same committed stock state.
    foreach ($children as $child) {
        fwrite($child['pipes'][0], "GO\n");
        fclose($child['pipes'][0]);
    }
    $results = [];
    foreach ($children as $child) {
        $output = stream_get_contents($child['pipes'][1]);
        $errors = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $exit = proc_close($child['process']);
        if ($exit !== 0) {
            throw new RuntimeException($errors.$output);
        }$results[] = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
    }

    return $results;
}
$lastItem = race($user, $warehouse, $variant, [(string) Str::uuid(), (string) Str::uuid()], '-1');
$statuses = array_column($lastItem, 'status');
sort($statuses);
if ($statuses !== ['posted', 'rejected'] || StockBalance::firstOrFail()->quantity !== '0.000' || StockMovement::count() !== 2) {
    throw new RuntimeException('Concurrent last-item verification failed.');
}
$key = (string) Str::uuid();
$retry = race($user, $warehouse, $variant, [$key, $key], '1');
if (array_column($retry, 'status') !== ['posted', 'posted'] || $retry[0]['id'] !== $retry[1]['id'] || StockBalance::firstOrFail()->quantity !== '1.000' || StockMovement::count() !== 3) {
    throw new RuntimeException('Concurrent idempotent retry verification failed.');
}
$reconciled = app(StockLedger::class)->reconcile($user, $warehouse->id);
if (! $reconciled['balanced']) {
    throw new RuntimeException('Concurrency ledger does not reconcile.');
}
echo json_encode(['last_item' => $lastItem, 'same_request' => $retry, 'reconciliation' => $reconciled], JSON_PRETTY_PRINT)."\n";

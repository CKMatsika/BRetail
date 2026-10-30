<?php

use App\Models\User;
use App\Services\StockLedger;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'bretail_dev') {
    throw new RuntimeException('Concurrency workers require the isolated local development database.');
}
$user = User::findOrFail((int) $argv[1]);
app(TenantContext::class)->id = $user->tenant_id;
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
try {
    $document = app(StockLedger::class)->post($user, ['request_id' => $argv[4], 'warehouse_id' => (int) $argv[2], 'type' => 'adjustment', 'reference' => 'Concurrency verification', 'reason' => 'Isolated test tenant', 'lines' => [['variant_id' => (int) $argv[3], 'quantity' => $argv[5], 'unit_cost' => '10']]]);
    echo json_encode(['status' => 'posted', 'id' => $document->id])."\n";
} catch (ValidationException $e) {
    echo json_encode(['status' => 'rejected', 'errors' => $e->errors()])."\n";
}

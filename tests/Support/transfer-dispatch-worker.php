<?php

use App\Models\User;
use App\Services\BranchTransfers;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$tenantId, $userId, $transferId, $requestId, $date, $variantId, $quantity, $barrierDir, $workerName] = array_slice($argv, 1);
app(TenantContext::class)->id = (int) $tenantId;
$user = User::findOrFail((int) $userId);

file_put_contents($barrierDir.'/ready-'.$workerName, 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrierDir.'/go') && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! file_exists($barrierDir.'/go')) {
    echo json_encode(['result' => 'start_timeout']);
    exit(1);
}

try {
    app(BranchTransfers::class)->post($user, (int) $transferId, 'dispatch', [
        'request_id' => $requestId,
        'date' => $date,
        'items' => [['variant_id' => (int) $variantId, 'quantity' => $quantity]],
    ]);
    echo json_encode(['result' => 'posted']);
} catch (Throwable $e) {
    echo json_encode(['result' => 'rejected', 'message' => $e->getMessage()]);
}

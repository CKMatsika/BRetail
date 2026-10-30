<?php

use App\Models\User;
use App\Services\PosSales;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'bretail_dev') {
    throw new RuntimeException('Use the isolated local development database only.');
}
$u = User::findOrFail((int) $argv[1]);
app(TenantContext::class)->id = $u->tenant_id;
$payload = json_decode(base64_decode($argv[2]), true, 64, JSON_THROW_ON_ERROR);
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
try {
    $sale = app(PosSales::class)->checkout($u, $payload);
    echo json_encode(['status' => 'posted', 'id' => $sale->id])."\n";
} catch (ValidationException $e) {
    echo json_encode(['status' => 'rejected', 'errors' => $e->errors()])."\n";
}

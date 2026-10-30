<?php

namespace Tests\Feature;

use App\Models\BranchTransfer;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\TransferEvent;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\BranchTransfers;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\StockLedger;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ZZZBranchTransferConcurrencyTest extends TestCase
{
    public function test_two_postgres_workers_cannot_dispatch_one_approved_transfer_twice(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent transfer workers require PostgreSQL.');
        }

        $user = app(Onboarding::class)->register([
            'name' => 'Owner', 'email' => 'transfer-race@example.test', 'password' => 'SecurePassword123',
            'company_name' => 'Transfer Race', 'industry' => 'general', 'base_currency' => 'USD',
            'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main',
        ]);
        TenantModule::whereIn('key', ['inventory', 'accounting'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($user, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $source = Warehouse::firstOrFail();
        $destination = Warehouse::create(['branch_id' => $source->branch_id, 'name' => 'Destination']);
        $product = app(Catalog::class)->product($user, ['name' => 'Racing item', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $variant = app(Catalog::class)->variant($user, $product, ['name' => 'Standard', 'sku' => 'RACE-ITEM', 'cost_price' => '4', 'selling_price' => '8', 'wholesale_price' => '8', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        app(StockLedger::class)->post($user, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $source->id, 'type' => 'opening', 'reference' => 'RACE-OPEN', 'reason' => 'Test', 'lines' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_cost' => '4']]]);
        $transfers = app(BranchTransfers::class);
        $transfer = $transfers->create($user, ['request_id' => (string) Str::uuid(), 'source_id' => $source->id, 'destination_id' => $destination->id, 'date' => $today, 'reference' => 'RACE-TRANSFER', 'items' => [['variant_id' => $variant->id, 'quantity' => '1']]]);
        $transfers->transition($user, $transfer->id, 'approve');

        $workerFile = base_path('tests/Support/transfer-dispatch-worker.php');
        $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'br-transfer-'.Str::uuid();
        mkdir($barrierDir);
        $startWorker = fn (string $name) => new Process([
            PHP_BINARY, $workerFile, (string) $user->tenant_id, (string) $user->id, (string) $transfer->id,
            (string) Str::uuid(), $today, (string) $variant->id, '1', $barrierDir, $name,
        ], base_path(), null, null, 30);
        $first = $startWorker('first');
        $second = $startWorker('second');
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 15;
            while ((! file_exists($barrierDir.'/ready-first') || ! file_exists($barrierDir.'/ready-second')) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrierDir.'/ready-first');
            $this->assertFileExists($barrierDir.'/ready-second');
            touch($barrierDir.'/go');
            $first->wait();
            $second->wait();
            $results = [json_decode($first->getOutput(), true), json_decode($second->getOutput(), true)];
        } finally {
            foreach (['ready-first', 'ready-second', 'go'] as $file) {
                @unlink($barrierDir.'/'.$file);
            }
            @rmdir($barrierDir);
        }

        $this->assertSame(['posted', 'rejected'], collect($results)->pluck('result')->sort()->values()->all());
        $this->assertSame(1, TransferEvent::where('transfer_id', $transfer->id)->where('kind', 'dispatch')->count());
        $this->assertSame('0.000', StockBalance::where('warehouse_id', $source->id)->where('variant_id', $variant->id)->value('quantity'));
        $this->assertSame('in_transit', BranchTransfer::findOrFail($transfer->id)->status);
    }
}

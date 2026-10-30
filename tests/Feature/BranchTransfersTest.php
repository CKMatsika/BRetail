<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\TenantModule;
use App\Models\TransferEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\BranchTransfers;
use App\Services\Catalog;
use App\Services\Onboarding;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class BranchTransfersTest extends TestCase
{
    use RefreshDatabase;

    public static function outcomes(): array { return ['received'=>[false], 'lost_in_transit'=>[true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function test_cross_branch_partial_receipts_preserve_value_and_retries(bool $loss): void
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'transfer@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Transfers', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($u, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $src = Warehouse::first();
        $b = Branch::create(['name' => 'Other', 'code' => 'OTHER', 'active' => true]);
        $dst = Warehouse::create(['branch_id' => $b->id, 'name' => 'Other']);
        $p = app(Catalog::class)->product($u, ['name' => 'Item', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $v = app(Catalog::class)->variant($u, $p, ['name' => 'Standard', 'sku' => 'TRANSFER', 'cost_price' => '3.3333', 'selling_price' => '10', 'wholesale_price' => '10', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        app(StockLedger::class)->post($u, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $src->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Test opening', 'lines' => [['variant_id' => $v->id, 'quantity' => '3', 'unit_cost' => '3.3333']]]);
        $s = app(BranchTransfers::class);
        $t = $s->create($u, ['request_id' => (string) Str::uuid(), 'source_id' => $src->id, 'destination_id' => $dst->id, 'date' => $today, 'reference' => 'TRANSFER', 'items' => [['variant_id' => $v->id, 'quantity' => '3']]]);
        $s->transition($u, $t->id, 'approve');
        $d = ['request_id' => (string) Str::uuid(), 'date' => $today, 'items' => [['variant_id' => $v->id, 'quantity' => '3']]];
        $e = $s->post($u, $t->id, 'dispatch', $d);
        $this->assertSame($e->id, $s->post($u, $t->id, 'dispatch', $d)->id);
        $l = app(AccountingLedger::class);
        $this->assertSame('9.9999', $l->balance($l->account('inventory_transit')->id, 'training'));
        foreach (['1', '2'] as $qty) {
            $r = ['request_id' => (string) Str::uuid(), 'date' => $today, 'reason'=>'Transit damage verified', 'items' => [['variant_id' => $v->id, 'quantity' => $qty]]];
            $event = $s->post($u, $t->id, $loss && $qty==='2'?'loss':'receive', $r);
            $this->assertSame($event->id, $s->post($u, $t->id, $loss && $qty==='2'?'loss':'receive', $r)->id);
        }
        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame($loss?'3.3333':'9.9999', StockBalance::where('warehouse_id', $dst->id)->first()->value);
        $this->assertSame('0.0000', $l->balance($l->account('inventory_transit')->id, 'training'));
        $this->assertSame('0.0000', $l->balance($l->account('branch_clearing')->id, 'training'));
        $this->assertTrue(app(StockLedger::class)->reconcile($u,$dst->id)['balanced']);
    }

    public function test_cross_branch_receive_requires_access_to_both_branches(): void
    {
        $owner = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'transfer-access@example.test', 'password' => 'SecurePassword123', 'company_name' => 'Transfer Access', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        app(AccountingSetup::class)->initialize($owner, ['name' => 'FY', 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $source = Warehouse::firstOrFail();
        $destinationBranch = Branch::create(['name' => 'Destination', 'code' => 'DEST', 'active' => true]);
        $destination = Warehouse::create(['branch_id' => $destinationBranch->id, 'name' => 'Destination']);
        $product = app(Catalog::class)->product($owner, ['name' => 'Item', 'unit' => 'each', 'tax_class' => 'zero', 'warranty_months' => 0, 'active' => true, 'attributes' => []]);
        $variant = app(Catalog::class)->variant($owner, $product, ['name' => 'Standard', 'sku' => 'TRANSFER-ACCESS', 'cost_price' => '4', 'selling_price' => '8', 'wholesale_price' => '8', 'minimum_stock' => '0', 'reorder_level' => '0', 'active' => true, 'attributes' => []]);
        app(StockLedger::class)->post($owner, ['request_id' => (string) Str::uuid(), 'warehouse_id' => $source->id, 'type' => 'opening', 'reference' => 'OPEN', 'reason' => 'Test', 'lines' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_cost' => '4']]]);
        $service = app(BranchTransfers::class);
        $transfer = $service->create($owner, ['request_id' => (string) Str::uuid(), 'source_id' => $source->id, 'destination_id' => $destination->id, 'date' => $today, 'reference' => 'ACCESS-TRANSFER', 'items' => [['variant_id' => $variant->id, 'quantity' => '1']]]);
        $service->transition($owner, $transfer->id, 'approve');
        $service->post($owner, $transfer->id, 'dispatch', ['request_id' => (string) Str::uuid(), 'date' => $today, 'items' => [['variant_id' => $variant->id, 'quantity' => '1']]]);
        $receiver = new User(['name' => 'Destination Receiver', 'email' => 'receiver@example.test', 'password' => 'SecurePassword123']);
        $receiver->tenant_id = $owner->tenant_id;
        $receiver->role_id = Role::where('name', 'Inventory Manager')->value('id');
        $receiver->save();
        $receiver->branches()->attach($destinationBranch->id, ['tenant_id' => $owner->tenant_id]);
        $receipt = ['request_id' => (string) Str::uuid(), 'date' => $today, 'items' => [['variant_id' => $variant->id, 'quantity' => '1']]];

        try {
            $service->post($receiver, $transfer->id, 'receive', $receipt);
            $this->fail('A receiver without source-branch authority must not post paired branch journals.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, TransferEvent::where('transfer_id', $transfer->id)->where('kind', 'receive')->count());
        }

        $receiver->branches()->attach($source->branch_id, ['tenant_id' => $owner->tenant_id]);
        $event = $service->post($receiver, $transfer->id, 'receive', $receipt);
        $this->assertSame('receive', $event->kind);
        $this->assertSame('completed', $transfer->fresh()->status);
    }
}

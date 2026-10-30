<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantModule;
use App\Services\Onboarding;
use App\Services\Suppliers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_codes_are_normalized_unique_and_edits_preserve_identity(): void
    {
        $owner = app(Onboarding::class)->register(['name'=>'Owner','email'=>'supplier@example.test','password'=>'SecurePassword123','company_name'=>'Purchasing','industry'=>'general','base_currency'=>'USD','branch_name'=>'Main','branch_code'=>'MAIN','warehouse_name'=>'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'purchasing'])->update(['enabled'=>true]);
        $data=['code'=>' sup-01 ','name'=>'First supplier','terms_days'=>30,'active'=>true];
        $supplier=app(Suppliers::class)->save($owner,$data);
        $this->assertSame('SUP-01',$supplier->code);
        $edited=app(Suppliers::class)->save($owner,[...$data,'name'=>'Renamed supplier','active'=>false],$supplier->id);
        $this->assertSame($supplier->id,$edited->id);
        $this->assertFalse($edited->active);
        try {
            app(Suppliers::class)->save($owner,$data);
            $this->fail('Duplicate supplier code accepted.');
        } catch (ValidationException) {
            $this->assertSame(1,Supplier::count());
        }
    }
}

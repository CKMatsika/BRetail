<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained();
            foreach (['supplier_id'=>'suppliers','warehouse_id'=>'warehouses','actor_id'=>'users'] as $field=>$table) {
                $t->unsignedBigInteger($field); $t->foreign(['tenant_id',$field])->references(['tenant_id','id'])->on($table);
            }
            $t->uuid('request_id'); $t->string('payload_hash',64); $t->string('book',12);
            $t->string('number',40); $t->string('supplier_name',160); $t->date('date');
            $t->date('expected_on')->nullable(); $t->text('notes')->nullable();
            $t->string('status',24)->default('draft'); $t->json('items');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->foreign(['tenant_id','approved_by'])->references(['tenant_id','id'])->on('users');
            $t->timestamp('approved_at')->nullable(); $t->timestamps();
            $t->unique(['tenant_id','id']); $t->unique(['tenant_id','request_id']); $t->unique(['tenant_id','number']);
        });
    }
    public function down(): void {
        if(DB::table('purchase_orders')->exists())throw new RuntimeException('Preserve purchase orders before considering rollback.');
        Schema::dropIfExists('purchase_orders');
    }
};

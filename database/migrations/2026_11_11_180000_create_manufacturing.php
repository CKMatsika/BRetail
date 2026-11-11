<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_recipes', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained(); $t->string('name', 160);
            $t->unsignedBigInteger('output_variant_id'); $t->decimal('batch_quantity', 18, 3)->default(1);
            $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'output_variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
        });
        Schema::create('production_recipe_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained(); $t->unsignedBigInteger('recipe_id');
            $t->unsignedBigInteger('variant_id'); $t->decimal('quantity', 18, 3); $t->timestamps();
            $t->foreign(['tenant_id', 'recipe_id'])->references(['tenant_id', 'id'])->on('production_recipes')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
            $t->unique(['recipe_id', 'variant_id']);
        });
        Schema::create('production_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained(); $t->unsignedBigInteger('recipe_id');
            $t->unsignedBigInteger('warehouse_id'); $t->string('reference', 80); $t->decimal('quantity', 18, 3);
            $t->string('status', 20)->default('planned'); $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users'); $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->decimal('material_cost', 24, 4)->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'id']); $t->unique(['tenant_id', 'reference']);
            $t->foreign(['tenant_id', 'recipe_id'])->references(['tenant_id', 'id'])->on('production_recipes');
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('production_order_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained(); $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('variant_id'); $t->decimal('quantity', 18, 3); $t->decimal('unit_cost', 24, 4); $t->decimal('value', 24, 4);
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('production_orders')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
            $t->unique(['order_id', 'variant_id']);
        });

        DB::table('tenants')->orderBy('id')->each(function ($tenant) {
            DB::table('tenant_modules')->insertOrIgnore(['tenant_id' => $tenant->id, 'key' => 'manufacturing', 'enabled' => false, 'settings' => json_encode(['preferred' => false]), 'created_at' => now(), 'updated_at' => now()]);
        });
        foreach (DB::table('roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            $add = match ($role->name) {
                'General Manager', 'Branch Manager', 'Inventory Manager' => ['manufacturing.view', 'manufacturing.manage', 'manufacturing.produce'],
                'Accountant', 'Auditor' => ['manufacturing.view'],
                default => [],
            };
            if ($add) DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$add]))) ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_lines'); Schema::dropIfExists('production_orders');
        Schema::dropIfExists('production_recipe_lines'); Schema::dropIfExists('production_recipes');
        DB::table('tenant_modules')->where('key', 'manufacturing')->delete();
    }
};

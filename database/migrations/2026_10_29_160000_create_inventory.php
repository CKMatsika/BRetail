<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->unique(['tenant_id', 'id']));
        Schema::create('classifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('level', 20);
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name', 120);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'level', 'parent_id', 'name'], 'classification_names_unique');
            $t->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('classifications');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name', 160);
            $t->text('description')->nullable();
            foreach (['department_id', 'category_id', 'subcategory_id'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on('classifications');
            }$t->string('brand', 120)->nullable();
            $t->string('unit', 30)->default('each');
            $t->string('tax_class', 60)->default('standard');
            $t->unsignedInteger('warranty_months')->default(0);
            $t->json('attributes');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('product_id');
            $t->string('name', 160);
            $t->string('sku', 80);
            $t->string('barcode', 80)->nullable();
            $t->json('attributes');
            $t->decimal('cost_price', 24, 4)->default(0);
            $t->decimal('selling_price', 24, 4)->default(0);
            $t->decimal('wholesale_price', 24, 4)->default(0);
            $t->decimal('reorder_level', 18, 3)->default(0);
            $t->decimal('minimum_stock', 18, 3)->default(0);
            $t->decimal('maximum_stock', 18, 3)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'sku']);
            $t->unique(['tenant_id', 'barcode']);
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('type', 30);
            $t->unsignedBigInteger('warehouse_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('reference', 160);
            $t->text('reason');
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('variant_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_cost', 24, 4);
            $t->decimal('value', 24, 4);
            $t->timestamp('created_at');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('stock_documents');
            $t->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
            $t->unique(['document_id', 'variant_id']);
            $t->index(['tenant_id', 'warehouse_id', 'variant_id', 'id'], 'stock_history_index');
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('variant_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('quantity', 18, 3)->default(0);
            $t->decimal('value', 24, 4)->default(0);
            $t->decimal('average_cost', 24, 4)->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'variant_id', 'warehouse_id'], 'stock_balance_unique');
            $t->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('warehouse_id');
            $t->string('reference', 160);
            $t->string('status', 20)->default('draft');
            $t->unsignedBigInteger('watermark')->default(0);
            $t->foreignId('actor_id')->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('count_id');
            $t->unsignedBigInteger('variant_id');
            $t->decimal('expected_quantity', 18, 3);
            $t->decimal('counted_quantity', 18, 3)->nullable();
            $t->foreign(['tenant_id', 'count_id'])->references(['tenant_id', 'id'])->on('stock_counts');
            $t->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants');
            $t->unique(['count_id', 'variant_id']);
        });
        foreach (DB::table('roles')->get() as $role) {
            $extra = config('inventory.role_permissions.'.$role->name, []);
            if ($extra) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...$extra])))]);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION inventory_immutable() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Posted inventory records are immutable''; END';");
            foreach (['stock_documents', 'stock_movements'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
            }
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT positive_stock CHECK (quantity >= 0 AND value >= 0 AND average_cost >= 0)');
        }
    }

    public function down(): void
    {
        foreach (['stock_count_lines', 'stock_counts', 'stock_balances', 'stock_movements', 'stock_documents', 'product_variants', 'products', 'classifications'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS inventory_immutable()');
        }
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropUnique(['tenant_id', 'id']));
    }
};

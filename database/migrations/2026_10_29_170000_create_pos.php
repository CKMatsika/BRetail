<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('tenant_id')->constrained();
        $t->unique(['tenant_id', 'id']);
    }

    private function link(Blueprint $t, string $column, string $table): void
    {
        $t->unsignedBigInteger($column);
        $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table);
    }

    public function up(): void
    {
        Schema::create('pos_customers', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 160);
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('pos_sessions', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'warehouse_id', 'warehouses');
            $this->link($t, 'user_id', 'users');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('status', 16)->default('open');
            $t->string('currency', 3);
            $t->decimal('opening_cash', 20, 2);
            $t->decimal('counted_cash', 20, 2)->nullable();
            $t->decimal('expected_cash', 20, 2)->nullable();
            $t->decimal('variance', 20, 2)->nullable();
            $t->uuid('close_request_id')->nullable();
            $t->string('close_payload_hash', 64)->nullable();
            $t->text('closing_note')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->unique(['tenant_id', 'request_id']);
            $t->index(['tenant_id', 'user_id', 'status']);
        });
        Schema::create('pos_holds', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'warehouse_id', 'warehouses');
            $this->link($t, 'user_id', 'users');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('pos_customers');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('label', 120);
            $t->json('cart');
            $t->string('status', 16)->default('held');
            $t->timestamps();
            $t->unique(['tenant_id', 'request_id']);
        });
        Schema::create('pos_sales', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'session_id', 'pos_sessions');
            $this->link($t, 'warehouse_id', 'warehouses');
            $this->link($t, 'user_id', 'users');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('pos_customers');
            $t->unsignedBigInteger('hold_id')->nullable();
            $t->foreign(['tenant_id', 'hold_id'])->references(['tenant_id', 'id'])->on('pos_holds');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('sequence');
            $t->string('receipt_number', 100);
            $t->string('currency', 3);
            $t->string('mode', 16);
            $t->json('company_snapshot');
            $t->string('customer_name', 160)->nullable();
            $t->string('cashier_name', 120);
            $t->string('warehouse_name', 120);
            foreach (['subtotal', 'discount', 'tax', 'total', 'change'] as $c) {
                $t->decimal($c, 20, 2);
            }$t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'sequence']);
            $t->unique(['tenant_id', 'receipt_number']);
            $t->index(['tenant_id', 'warehouse_id', 'created_at']);
        });
        Schema::create('pos_sale_items', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'sale_id', 'pos_sales');
            $this->link($t, 'variant_id', 'product_variants');
            $t->string('description', 255);
            $t->string('sku', 80);
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 24, 4);
            $t->decimal('discount_percent', 7, 2);
            $t->decimal('tax_rate', 9, 4);
            foreach (['subtotal', 'discount', 'net', 'tax', 'total'] as $c) {
                $t->decimal($c, 20, 2);
            }$t->decimal('cost_value', 24, 4);
            $t->unique(['sale_id', 'variant_id']);
        });
        Schema::create('pos_payments', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'sale_id', 'pos_sales');
            $t->string('method', 20);
            $t->decimal('amount', 20, 2);
            $t->decimal('tendered', 20, 2);
            $t->decimal('change', 20, 2);
            $t->string('reference', 160)->nullable();
            $t->unique(['sale_id', 'method']);
        });
        Schema::create('pos_returns', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'sale_id', 'pos_sales');
            $this->link($t, 'session_id', 'pos_sessions');
            $this->link($t, 'user_id', 'users');
            $this->link($t, 'warehouse_id', 'warehouses');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('sequence');
            $t->string('receipt_number', 100);
            $t->text('reason');
            $t->string('currency', 3);
            $t->string('mode', 16);
            foreach (['net', 'tax', 'total'] as $c) {
                $t->decimal($c, 20, 2);
            }$t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'sequence']);
        });
        Schema::create('pos_return_items', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'return_id', 'pos_returns');
            $this->link($t, 'sale_item_id', 'pos_sale_items');
            $this->link($t, 'variant_id', 'product_variants');
            $t->decimal('quantity', 18, 3);
            foreach (['net', 'tax', 'total'] as $c) {
                $t->decimal($c, 20, 2);
            }$t->decimal('cost_value', 24, 4);
            $t->unique(['return_id', 'sale_item_id']);
        });
        Schema::create('pos_refund_payments', function (Blueprint $t) {
            $this->owned($t);
            $this->link($t, 'return_id', 'pos_returns');
            $t->string('method', 20);
            $t->decimal('amount', 20, 2);
            $t->string('reference', 160)->nullable();
            $t->unique(['return_id', 'method']);
        });
        foreach (DB::table('roles')->get() as $role) {
            $extra = config('pos.role_permissions.'.$role->name, []);
            if ($extra) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...$extra])))]);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['pos_sales', 'pos_sale_items', 'pos_payments', 'pos_returns', 'pos_return_items', 'pos_refund_payments'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
            }
            DB::statement("CREATE UNIQUE INDEX pos_one_open_shift ON pos_sessions (tenant_id,user_id) WHERE status='open'");
            DB::statement('ALTER TABLE pos_sale_items ADD CONSTRAINT pos_positive_sale_quantity CHECK (quantity > 0 AND net >= 0 AND tax >= 0 AND total >= 0 AND cost_value >= 0)');
            DB::statement('ALTER TABLE pos_return_items ADD CONSTRAINT pos_positive_return_quantity CHECK (quantity > 0 AND net >= 0 AND tax >= 0 AND total >= 0 AND cost_value >= 0)');
        }
    }

    public function down(): void
    {
        foreach (['pos_refund_payments', 'pos_return_items', 'pos_returns', 'pos_payments', 'pos_sale_items', 'pos_sales', 'pos_holds', 'pos_sessions', 'pos_customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

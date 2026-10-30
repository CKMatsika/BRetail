<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            foreach (['warehouse_id' => 'warehouses', 'actor_id' => 'users'] as $c => $table) {
                $t->unsignedBigInteger($c);
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on($table);
            }
            foreach (['supplier_id' => 'suppliers', 'customer_id' => 'pos_customers', 'order_id' => 'purchase_orders', 'parent_id' => 'trade_documents'] as $c => $table) {
                $t->unsignedBigInteger($c)->nullable();
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on($table);
            }
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('kind', 24);
            $t->string('book', 12);
            $t->string('number', 40);
            $t->string('reference', 100);
            $t->string('party_name', 160);
            $t->date('date');
            $t->date('due_on')->nullable();
            $t->json('items');
            $t->decimal('net', 24, 4);
            $t->decimal('tax', 24, 4);
            $t->decimal('total', 24, 4);
            $t->text('notes')->nullable();
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'kind', 'book']);
        });
        Schema::create('trade_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            foreach (['document_id', 'target_id'] as $c) {
                $t->unsignedBigInteger($c);
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on('trade_documents');
            }$t->decimal('amount', 24, 4);
            $t->unique(['document_id', 'target_id']);
        });
        Schema::create('customer_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('customer_id');
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('pos_customers');
            $t->decimal('credit_limit', 24, 2);
            $t->unsignedSmallInteger('terms_days');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'customer_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['trade_documents', 'trade_allocations'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
            }
            DB::statement("CREATE UNIQUE INDEX trade_supplier_invoice ON trade_documents(tenant_id,book,supplier_id,reference) WHERE kind='bill'");
            DB::statement("CREATE UNIQUE INDEX trade_quote_conversion ON trade_documents(tenant_id,parent_id) WHERE kind='invoice' AND parent_id IS NOT NULL");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchase_order_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Purchase orders cannot be deleted'; END IF;
 IF (to_jsonb(NEW)-'status'-'approved_by'-'approved_at'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'approved_by'-'approved_at'-'updated_at') THEN RAISE EXCEPTION 'Order identity and lines are immutable'; END IF;
 IF NOT ((OLD.status='draft' AND NEW.status IN ('approved','cancelled')) OR (OLD.status IN ('approved','partially_received') AND NEW.status IN ('partially_received','completed','cancelled'))) THEN RAISE EXCEPTION 'Invalid order transition'; END IF;
 IF OLD.status<>'draft' AND (NEW.approved_by IS DISTINCT FROM OLD.approved_by OR NEW.approved_at IS DISTINCT FROM OLD.approved_at) THEN RAISE EXCEPTION 'Approval is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchase_order_guard BEFORE UPDATE OR DELETE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION purchase_order_guard();
SQL);
        }
        foreach (DB::table('roles')->get() as $r) {
            $extra = config('trade.role_permissions.'.$r->name, []);
            if ($extra) {
                DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($r->permissions, true), ...$extra])))]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('trade_documents')->exists()) {
            throw new RuntimeException('Cannot remove posted trade history.');
        }foreach (['customer_credits', 'trade_allocations', 'trade_documents'] as $t) {
            Schema::dropIfExists($t);
        }if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS purchase_order_guard ON purchase_orders; DROP FUNCTION IF EXISTS purchase_order_guard();');
        }
    }
};

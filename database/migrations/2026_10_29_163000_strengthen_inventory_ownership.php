<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $t) {
            $t->unique(['tenant_id', 'id', 'warehouse_id'], 'stock_document_warehouse_unique');
            $t->foreign(['tenant_id', 'actor_id'], 'stock_document_actor_tenant_fk')->references(['tenant_id', 'id'])->on('users');
        });
        Schema::table('stock_movements', fn (Blueprint $t) => $t->foreign(['tenant_id', 'document_id', 'warehouse_id'], 'stock_movement_document_warehouse_fk')->references(['tenant_id', 'id', 'warehouse_id'])->on('stock_documents'));
        Schema::table('stock_counts', fn (Blueprint $t) => $t->foreign(['tenant_id', 'actor_id'], 'stock_count_actor_tenant_fk')->references(['tenant_id', 'id'])->on('users'));
    }

    public function down(): void
    {
        Schema::table('stock_counts', fn (Blueprint $t) => $t->dropForeign('stock_count_actor_tenant_fk'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropForeign('stock_movement_document_warehouse_fk'));
        Schema::table('stock_documents', function (Blueprint $t) {
            $t->dropForeign('stock_document_actor_tenant_fk');
            $t->dropUnique('stock_document_warehouse_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->index(['tenant_id', 'parent_id', 'kind'], 'trade_document_versions_lookup'));
        Schema::create('trade_document_details', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('document_id');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('trade_documents');
            $t->unsignedBigInteger('actor_id');
            $t->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'id'])->on('users');
            $t->uuid('request_id');
            $t->unique(['tenant_id', 'request_id']);
            $t->string('payload_hash', 64);
            $t->string('reference', 100);
            $t->text('notes')->nullable();
            $t->timestamp('created_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER trade_document_details_immutable BEFORE UPDATE OR DELETE ON trade_document_details FOR EACH ROW EXECUTE FUNCTION inventory_immutable()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_document_details');
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropIndex('trade_document_versions_lookup'));
    }
};

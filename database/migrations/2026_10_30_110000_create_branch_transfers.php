<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('destination_id');
            $t->string('book', 16);
            $t->string('status', 30)->default('draft');
            $t->date('date');
            $t->string('reference', 160);
            $t->json('items');
            $t->timestamps();
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'id']);
            foreach (['source_id', 'destination_id'] as $c) {
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on('warehouses');
            }
        });
        Schema::create('transfer_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('transfer_id');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('kind', 24);
            $t->date('date');
            $t->json('items');
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->foreign(['tenant_id', 'transfer_id'])->references(['tenant_id', 'id'])->on('branch_transfers');
        });
    }

    public function down(): void
    {
        if (DB::table('branch_transfers')->exists()) {
            throw new RuntimeException('Transfer history must be preserved.');
        }Schema::dropIfExists('transfer_events');
        Schema::dropIfExists('branch_transfers');
    }
};
